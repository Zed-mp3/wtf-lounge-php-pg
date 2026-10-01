<?php
/**
 * Database connection (PDO / PostgreSQL).
 * Set DB_* environment variables, or edit the defaults below,
 * then load sql/db_shop.sql into the database before using the site:
 *   createdb db_shop && psql -d db_shop -f sql/db_shop.sql
 */
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '5432');
define('DB_NAME', getenv('DB_NAME') ?: 'db_shop');
define('DB_USER', getenv('DB_USER') ?: 'postgres');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
// Supabase requires SSL: set DB_SSLMODE=require there. Local Postgres: leave as 'prefer'.
define('DB_SSLMODE', getenv('DB_SSLMODE') ?: 'prefer');

// Turn this off in production — it controls whether raw DB error
// details are shown to visitors on a connection failure.
define('APP_DEBUG', getenv('APP_DEBUG') === 'true' || getenv('APP_DEBUG') === false);

try {
    $pdo = new PDO(
        "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";sslmode=" . DB_SSLMODE,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    $detail = APP_DEBUG ? ' (' . $e->getMessage() . ')' : '';
    die('Database connection failed. Make sure PostgreSQL is running and sql/db_shop.sql has been loaded.' . $detail);
}
