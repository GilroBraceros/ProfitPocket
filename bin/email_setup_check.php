<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require __DIR__.'/../config/database.php'; require __DIR__.'/../includes/email_diagnostics.php';
try {
    $c=email_config();
    $status=email_diagnostic_status(db(),$c);
    if (in_array('--verify-sender',$argv,true)) { $status['provider_sender_status']=brevo_sender_status($c); }
    echo json_encode($status,JSON_PRETTY_PRINT).PHP_EOL;
    foreach (['process_notifications.php','generate_reminders.php','subscription_maintenance.php','deploy_upgrade.php','production_setup.php','check_test_email.php'] as $script) { echo escapeshellarg(PHP_BINARY).' '.escapeshellarg(realpath(__DIR__.'/'.$script)).PHP_EOL; }
} catch (Throwable $e) { fwrite(STDERR,"Email setup check failed. Check private configuration and approved migration.\n"); exit(1); }
