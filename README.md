## Intro
KingbaseES driver implementation for Laravel

## Install

```shell
composer require lmo/laravel-kdb
```

## Config

1. Add provider to `providers` in `config/app.php`:

```
'providers' => [
    ...
    \Lmo\LaravelKdb\KingbaseServiceProvider::class,
    ...
],
```

2. Add driver configs to `connections` in `config/database.php`:
```
'connections' => [
    ...
        'kdb' => [
            'driver' => 'kdb',
            'url' => env('DATABASE_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '54321'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'schema' => 'public',
            'sslmode' => 'prefer',
        ],
    ...
],
```