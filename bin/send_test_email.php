<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require __DIR__.'/../includes/subscriptions.php';
require __DIR__.'/../includes/email_diagnostics.php';
try {
    if (!in_array('--confirm',$argv,true)) { throw new RuntimeException('Privately authorize the single test inbox, then run with --confirm.'); }
    echo json_encode(email_send_diagnostic(db(),email_config(),upgrade_config()),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR,'Test not completed: '.($e instanceof PDOException?'Database logging is unavailable.':$e->getMessage()).PHP_EOL); exit(1); }
