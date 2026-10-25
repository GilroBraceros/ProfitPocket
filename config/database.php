<?php
declare(strict_types=1);

require_once __DIR__.'/upgrade.php';
require_once __DIR__.'/database_bootstrap.php';

// Web PHP and cron use the same private settings; environment variables win.
function database_config(): array
{
    $file=getenv('PROFITPOCKET_DATABASE_CONFIG')?:upgrade_private_root().'/database.php';
    $private=[];
    if (is_file($file)) {
        $resolved=realpath($file);
        if (!$resolved || upgrade_public_path($resolved)) { throw new RuntimeException('Database configuration must be outside the web root.'); }
        ob_start(); try { $private=require $resolved; } finally { ob_end_clean(); }
        if (!is_array($private)) { throw new RuntimeException('Invalid private database configuration.'); }
    }
    $values=['PP_DB_HOST'=>'127.0.0.1','PP_DB_PORT'=>'3306','PP_DB_NAME'=>'profitpocket_db','PP_DB_USER'=>'root','PP_DB_PASSWORD'=>''];
    foreach ($values as $key=>$default) { $env=getenv($key); $values[$key]=$env!==false?$env:($private[$key]??$default); }
    foreach (['PP_DB_HOST','PP_DB_NAME','PP_DB_USER'] as $key) {
        if (!is_string($values[$key]) || $values[$key]==='' || preg_match('/[;\r\n\x00]/',$values[$key])) { throw new RuntimeException('Invalid private database setting.'); }
    }
    if (!ctype_digit((string)$values['PP_DB_PORT']) || (int)$values['PP_DB_PORT']<1 || (int)$values['PP_DB_PORT']>65535) { throw new RuntimeException('Invalid database port.'); }
    return $values;
}

// XAMPP defaults remain available locally. Never put credentials in a public file.
// Never put credentials in a public page or include them in an error message.
$databasePrivate=getenv('PROFITPOCKET_DATABASE_CONFIG')?:upgrade_private_root().'/database.php';
$databaseEnvironment=[];
foreach (['PP_DB_NAME','PP_DB_USER','PP_DB_PASSWORD'] as $databaseKey) { $databaseEnvironment[$databaseKey]=getenv($databaseKey); }
$databaseProject=realpath(__DIR__.'/..');
if (database_legacy_allowed($databaseProject,PHP_OS_FAMILY,is_file($databasePrivate) || getenv('PROFITPOCKET_DATABASE_CONFIG')!==false,$databaseEnvironment)) {
    $databaseLegacy=realpath($databaseProject.'/profitpocket/config/database.php');
    if ($databaseLegacy && $databaseLegacy===$databaseProject.'/profitpocket/config/database.php') { database_import_legacy($databaseLegacy); }
}
unset($databasePrivate,$databaseEnvironment,$databaseKey,$databaseProject,$databaseLegacy);

if (!function_exists('db')) {
function db(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }

    try {
        $settings=database_config();
        $host=$settings['PP_DB_HOST']; $port=$settings['PP_DB_PORT']; $name=$settings['PP_DB_NAME'];
        $user=$settings['PP_DB_USER']; $password=$settings['PP_DB_PASSWORD'];
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
    } catch (Throwable $exception) {
        error_log('ProfitPocket: database connection failed. Check private database configuration.');
        if (PHP_SAPI==='cli') { throw new RuntimeException('Database configuration or connection is unavailable.'); }
        http_response_code(503);
        header('Content-Type: text/html; charset=UTF-8');
        exit('<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>ProfitPocket unavailable</title><body><h1>Temporarily unavailable</h1><p>ProfitPocket cannot connect to its database. Please try again later.</p></body></html>');
    }
}
}
