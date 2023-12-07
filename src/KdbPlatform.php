<?php

namespace Lmo\LaravelKdb;

use Doctrine\DBAL\Platforms\PostgreSQL100Platform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnDiff;
use Doctrine\DBAL\Schema\Identifier;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\BinaryType;
use Doctrine\DBAL\Types\BlobType;
use Doctrine\Deprecations\Deprecation;

class KdbPlatform extends PostgreSQL100Platform
{
    /**
     * {@inheritDoc}
     */
    public function getAlterTableSQL(TableDiff $diff)
    {
        $sql         = [];
        $commentsSQL = [];
        $columnSql   = [];

        $table = $diff->getOldTable() ?? $diff->getName($this);

        $tableNameSQL = $this->wrap($table->getQuotedName($this));

        foreach ($diff->getAddedColumns() as $addedColumn) {
            if ($this->onSchemaAlterTableAddColumn($addedColumn, $diff, $columnSql)) {
                continue;
            }

            $query = 'ADD ' . $this->getColumnDeclarationSQL(
                    $this->wrap($addedColumn->getQuotedName($this)),
                    $addedColumn->toArray(),
                );

            $sql[] = 'ALTER TABLE ' . $tableNameSQL . ' ' . $query;

            $comment = $this->getColumnComment($addedColumn);

            if ($comment === null || $comment === '') {
                continue;
            }

            $commentsSQL[] = $this->getCommentOnColumnSQL(
                $tableNameSQL,
                $this->wrap($addedColumn->getQuotedName($this)),
                $comment,
            );
        }

        foreach ($diff->getDroppedColumns() as $droppedColumn) {
            if ($this->onSchemaAlterTableRemoveColumn($droppedColumn, $diff, $columnSql)) {
                continue;
            }

            $query = 'DROP ' . $this->wrap($droppedColumn->getQuotedName($this));
            $sql[] = 'ALTER TABLE ' . $tableNameSQL . ' ' . $query;
        }

        foreach ($diff->getModifiedColumns() as $columnDiff) {
            if ($this->onSchemaAlterTableChangeColumn($columnDiff, $diff, $columnSql)) {
                continue;
            }

            if ($this->isUnchangedBinaryColumn($columnDiff)) {
                continue;
            }

            $oldColumn = $columnDiff->getOldColumn() ?? $columnDiff->getOldColumnName();
            $newColumn = $columnDiff->getNewColumn();

            $oldColumnName = $this->wrap($oldColumn->getQuotedName($this));

            if (
                $columnDiff->hasTypeChanged()
                || $columnDiff->hasPrecisionChanged()
                || $columnDiff->hasScaleChanged()
                || $columnDiff->hasFixedChanged()
            ) {
                $type = $newColumn->getType();

                // SERIAL/BIGSERIAL are not "real" types and we can't alter a column to that type
                $columnDefinition                  = $newColumn->toArray();
                $columnDefinition['autoincrement'] = false;

                // here was a server version check before, but DBAL API does not support this anymore.
                $query = 'ALTER ' . $oldColumnName . ' TYPE ' . $type->getSQLDeclaration($columnDefinition, $this);
                $sql[] = 'ALTER TABLE ' . $tableNameSQL . ' ' . $query;
            }

            if ($columnDiff->hasDefaultChanged()) {
                $defaultClause = $newColumn->getDefault() === null
                    ? ' DROP DEFAULT'
                    : ' SET' . $this->getDefaultValueDeclarationSQL($newColumn->toArray());

                $query = 'ALTER ' . $oldColumnName . $defaultClause;
                $sql[] = 'ALTER TABLE ' . $tableNameSQL . ' ' . $query;
            }

            if ($columnDiff->hasNotNullChanged()) {
                $query = 'ALTER ' . $oldColumnName . ' ' . ($newColumn->getNotnull() ? 'SET' : 'DROP') . ' NOT NULL';
                $sql[] = 'ALTER TABLE ' . $tableNameSQL . ' ' . $query;
            }

            if ($columnDiff->hasAutoIncrementChanged()) {
                if ($newColumn->getAutoincrement()) {
                    // add autoincrement
                    $seqName = $this->getIdentitySequenceName(
                        $table->getName(),
                        $oldColumnName,
                    );

                    $sql[] = 'CREATE SEQUENCE ' . $seqName;
                    $sql[] = "SELECT setval('" . $seqName . "', (SELECT MAX(" . $oldColumnName . ') FROM '
                        . $tableNameSQL . '))';
                    $query = 'ALTER ' . $oldColumnName . " SET DEFAULT nextval('" . $seqName . "')";
                } else {
                    // Drop autoincrement, but do NOT drop the sequence. It might be re-used by other tables or have
                    $query = 'ALTER ' . $oldColumnName . ' DROP DEFAULT';
                }

                $sql[] = 'ALTER TABLE ' . $tableNameSQL . ' ' . $query;
            }

            $oldComment = $this->getOldColumnComment($columnDiff);
            $newComment = $this->getColumnComment($newColumn);

            if (
                $columnDiff->hasCommentChanged()
                || ($columnDiff->getOldColumn() !== null && $oldComment !== $newComment)
            ) {
                $commentsSQL[] = $this->getCommentOnColumnSQL(
                    $tableNameSQL,
                    $this->wrap($newColumn->getQuotedName($this)),
                    $newComment,
                );
            }

            if (! $columnDiff->hasLengthChanged()) {
                continue;
            }

            $query = 'ALTER ' . $oldColumnName . ' TYPE '
                . $newColumn->getType()->getSQLDeclaration($newColumn->toArray(), $this);
            $sql[] = 'ALTER TABLE ' . $tableNameSQL . ' ' . $query;
        }

        foreach ($diff->getRenamedColumns() as $oldColumnName => $column) {
            if ($this->onSchemaAlterTableRenameColumn($oldColumnName, $column, $diff, $columnSql)) {
                continue;
            }

            $oldColumnName = new Identifier($oldColumnName);

            $sql[] = 'ALTER TABLE ' . $tableNameSQL . ' RENAME COLUMN ' . $this->wrap($oldColumnName->getQuotedName($this))
                . ' TO ' . $this->wrap($column->getQuotedName($this));
        }

        $tableSql = [];

        if (! $this->onSchemaAlterTable($diff, $tableSql)) {
            $sql = array_merge($sql, $commentsSQL);

            $newName = $diff->getNewName();

            if ($newName !== false) {
                Deprecation::trigger(
                    'doctrine/dbal',
                    'https://github.com/doctrine/dbal/pull/5663',
                    'Generation of "rename table" SQL using %s is deprecated. Use getRenameTableSQL() instead.',
                    __METHOD__,
                );

                $sql[] = sprintf(
                    'ALTER TABLE %s RENAME TO %s',
                    $tableNameSQL,
                    $this->wrap($newName->getQuotedName($this)),
                );
            }

            $sql = array_merge(
                $this->getPreAlterTableIndexForeignKeySQL($diff),
                $sql,
                $this->getPostAlterTableIndexForeignKeySQL($diff),
            );
        }

        return array_merge($sql, $tableSql, $columnSql);
    }
    
    protected function wrap(string $name): string
    {
        return '"' . trim($name, '"') . '"';
    }

    private function getOldColumnComment(ColumnDiff $columnDiff): ?string
    {
        $oldColumn = $columnDiff->getOldColumn();

        if ($oldColumn !== null) {
            return $this->getColumnComment($oldColumn);
        }

        return null;
    }


    /**
     * Checks whether a given column diff is a logically unchanged binary type column.
     *
     * Used to determine whether a column alteration for a binary type column can be skipped.
     * Doctrine's {@see BinaryType} and {@see BlobType} are mapped to the same database column type on this platform
     * as this platform does not have a native VARBINARY/BINARY column type. Therefore the comparator
     * might detect differences for binary type columns which do not have to be propagated
     * to database as there actually is no difference at database level.
     */
    private function isUnchangedBinaryColumn(ColumnDiff $columnDiff): bool
    {
        $newColumnType = $columnDiff->getNewColumn()->getType();

        if (! $newColumnType instanceof BinaryType && ! $newColumnType instanceof BlobType) {
            return false;
        }

        $oldColumn = $columnDiff->getOldColumn() instanceof Column ? $columnDiff->getOldColumn() : null;

        if ($oldColumn !== null) {
            $oldColumnType = $oldColumn->getType();

            if (! $oldColumnType instanceof BinaryType && ! $oldColumnType instanceof BlobType) {
                return false;
            }

            return count(array_diff($columnDiff->changedProperties, ['type', 'length', 'fixed'])) === 0;
        }

        if ($columnDiff->hasTypeChanged()) {
            return false;
        }

        return count(array_diff($columnDiff->changedProperties, ['length', 'fixed'])) === 0;
    }

}