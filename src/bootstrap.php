<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;

// ── Database ─────────────────────────────────────────────────────
$capsule = new Capsule;

// Railway injects DATABASE_URL for PostgreSQL — use it if present
$databaseUrl = env('DATABASE_URL');

if ($databaseUrl) {
    // Parse postgres://user:pass@host:port/dbname
    $parsed = parse_url($databaseUrl);
    $capsule->addConnection([
        'driver'   => 'pgsql',
        'host'     => $parsed['host'],
        'port'     => $parsed['port'] ?? 5432,
        'database' => ltrim($parsed['path'] ?? '/aivo', '/'),
        'username' => $parsed['user'] ?? '',
        'password' => $parsed['pass'] ?? '',
        'charset'  => 'utf8',
        'prefix'   => '',
        'sslmode'  => 'require',
    ]);
} else {
    $driver   = env('DB_CONNECTION', 'sqlite');
    $database = env('DB_DATABASE', BASE_PATH . '/database/aivo.sqlite');

    if ($driver === 'sqlite') {
        if (!file_exists($database)) {
            touch($database);
        }
        $capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => $database,
            'prefix'   => '',
        ]);
    } else {
        $capsule->addConnection([
            'driver'    => $driver,
            'host'      => env('DB_HOST', '127.0.0.1'),
            'port'      => env('DB_PORT', '3306'),
            'database'  => env('DB_DATABASE', 'aivo'),
            'username'  => env('DB_USERNAME', 'root'),
            'password'  => env('DB_PASSWORD', ''),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
        ]);
    }
}

$capsule->setAsGlobal();
$capsule->bootEloquent();

// ── Run migrations on boot (safe to run repeatedly) ──────────────
require BASE_PATH . '/database/migrate.php';

// ── Stripe ───────────────────────────────────────────────────────
\Stripe\Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
\Stripe\Stripe::setApiVersion('2024-06-20');
