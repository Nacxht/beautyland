<?php

$envPath = __DIR__ . '/../.env';

if (!file_exists($envPath)) {
    die('File .env tidak ditemukan. Salin .env.example menjadi .env');
}

$lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

foreach ($lines as $line) {
    if (str_starts_with(trim($line), '#')) {
        continue;
    }

    [$key, $value] = explode('=', $line, 2);
    $_ENV[trim($key)] = trim($value);
}

define('HOSTNAME', $_ENV['DB_HOST'] ?? 'localhost');
define('DBNAME', $_ENV['DB_NAME'] ?? 'beautyland');
define('USERNAME', $_ENV['DB_USER'] ?? 'root');
define('PASSWORD', $_ENV['DB_PASS'] ?? '');
define('BASE_URL', rtrim($_ENV['BASE_URL'] ?? 'http://localhost/Beautyland', '/'));
