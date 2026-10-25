<?php
declare(strict_types=1);

function database_legacy_allowed(string $root,string $os,bool $privateFile,array $environment): bool
{
    if ($os!=='Linux' || $privateFile || !preg_match('~\A/home/[A-Za-z0-9._-]+/domains/profitpocket\.online/public_html\z~',$root)) { return false; }
    foreach (['PP_DB_NAME','PP_DB_USER','PP_DB_PASSWORD'] as $key) { if (($environment[$key]??false)!==false) { return false; } }
    return true;
}

function database_import_legacy(string $file): bool
{
    if (function_exists('db') || !is_file($file) || !is_readable($file)) { return false; }
    // Only accept the older ProfitPocket connection include. A second modern
    // loader cannot supply missing settings and would redeclare its helpers.
    $functions=[]; $tokens=token_get_all(file_get_contents($file));
    foreach ($tokens as $index=>$token) {
        if (is_array($token) && $token[0]===T_FUNCTION) {
            for ($next=$index+1;$next<count($tokens);$next++) {
                if (is_array($tokens[$next]) && $tokens[$next][0]===T_WHITESPACE) { continue; }
                if (is_array($tokens[$next]) && $tokens[$next][0]===T_STRING) { $functions[]=strtolower($tokens[$next][1]); }
                break;
            }
        }
    }
    if ($functions!==['db']) { return false; }
    // Execute the already-existing application connection include, without
    // copying, parsing, printing or changing any credential values.
    ob_start(); try { require_once $file; } finally { ob_end_clean(); }
    if (!function_exists('db') || realpath((new ReflectionFunction('db'))->getFileName())!==realpath($file)) { throw new RuntimeException('The existing database connection include is invalid.'); }
    define('PP_LEGACY_DATABASE_FILE',realpath($file));
    return true;
}
