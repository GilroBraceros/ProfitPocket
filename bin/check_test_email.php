<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require __DIR__.'/../includes/production.php';
require __DIR__.'/../includes/email_diagnostics.php';
try {
    $config=upgrade_config();
    production_check_location($config,realpath(__DIR__.'/..'),PHP_OS_FAMILY);
    $db=db(); production_require_environment($db,$config);
    echo json_encode(email_diagnostic_delivery($db,production_email_config($config),$config,true),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR,"Delivery check unavailable. Check the private production configuration and saved test attempt.\n"); exit(1); }
