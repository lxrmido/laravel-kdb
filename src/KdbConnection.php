<?php

namespace Lmo\LaravelKdb;

use Illuminate\Database\PostgresConnection;

class KdbConnection extends PostgresConnection
{
    /**
     * @return KdbDriver
     */
    protected function getDoctrineDriver()
    {
        return new KdbDriver();
    }
}