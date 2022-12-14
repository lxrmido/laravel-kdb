<?php

namespace Lmo\LaravelKdb;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Connection;

class KingbaseServiceProvider extends ServiceProvider {
    public function register()
    {
        $this->app->bind('db.connector.kdb', KdbConnector::class);
        Connection::resolverFor('kdb', function ($connection, $database, $prefix, $config) {
            return new KdbConnection($connection, $database, $prefix, $config);
        });
    }
}

