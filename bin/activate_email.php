<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require __DIR__.'/../includes/subscriptions.php';
require __DIR__.'/../includes/email_diagnostics.php';
try {
    if (!in_array('--confirm',$argv,true)) { throw new RuntimeException('Run with --confirm after privately configuring the key, verified sender, HTTPS URL and reset secret.'); }
    email_activate(db());
    echo "Sending enabled in private configuration. Verify the controlled test and delivery separately.\n";
} catch (Throwable $e) { fwrite(STDERR,'Activation stopped: '.($e instanceof PDOException?'Private database settings are unavailable.':$e->getMessage()).PHP_EOL); exit(1); }
