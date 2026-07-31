<?php
/**
 * Database connection settings for Mass Open.
 *
 * Credentials are read from environment variables so they never live in
 * source control. Set these in your web server / PHP-FPM pool config, e.g.:
 *
 *   MASSOPEN_DB_PORT=3306        (optional, defaults to 3306)
 */

return [
    'host'    => getenv('MASSOPEN_DB_HOST') ?: 'localhost',
    'name'    => getenv('MASSOPEN_DB_NAME') ?: '',
    'user'    => getenv('MASSOPEN_DB_USER') ?: '',
    'pass'    => getenv('MASSOPEN_DB_PASS') ?: '',
    'port'    => getenv('MASSOPEN_DB_PORT') ?: '3306',
    'charset' => 'utf8mb4',
];
