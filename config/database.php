<?php
declare(strict_types=1);

// XAMPP defaults. Set PP_DB_* environment variables for another local server.
// Never put credentials in a public page or include them in an error message.
function db(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $host = getenv('PP_DB_HOST') ?: '127.0.0.1';
    $port = getenv('PP_DB_PORT') ?: '3306';
    $name = getenv('PP_DB_NAME') ?: 'profitpocket_db';
    $user = getenv('PP_DB_USER') ?: 'root';
    $password = getenv('PP_DB_PASSWORD');

    try {
        $connection = new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $user,
            $password === false ? '' : $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]
        );
        $connection->exec("SET time_zone = '+08:00'");
        return $connection;
    } catch (PDOException $exception) {
        error_log('ProfitPocket: database connection failed. Check local database configuration.');
        http_response_code(503);
        header('Content-Type: text/html; charset=UTF-8');
        exit('<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>ProfitPocket unavailable</title><body><h1>Temporarily unavailable</h1><p>ProfitPocket cannot connect to its database. Please try again later.</p></body></html>');
    }
}
