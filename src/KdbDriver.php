<?php

namespace Lmo\LaravelKdb;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\PostgreSQL100Platform;
use Doctrine\DBAL\Platforms\PostgreSQL94Platform;
use Doctrine\Deprecations\Deprecation;
use Illuminate\Database\PDO\PostgresDriver;

class KdbDriver extends PostgresDriver
{
    /**
     * {@inheritDoc}
     */
    public function createDatabasePlatformForVersion($version)
    {
        return new KdbPlatform();
    }
}