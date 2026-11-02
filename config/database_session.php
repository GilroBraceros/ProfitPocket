<?php
declare(strict_types=1);

function database_initialize_session(PDO $connection): void
{
    // Request/connection settings only: no database, table or column is altered.
    // Match the existing ProfitPocket schema instead of a hosting-server default.
    $connection->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
    $connection->exec("SET time_zone = '+08:00'");
}
