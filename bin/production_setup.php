<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
ini_set('display_errors','0');
require __DIR__.'/../includes/production.php';
require __DIR__.'/../includes/email_diagnostics.php';
try {
    $config=upgrade_config();
    // Reject XAMPP, mismatched paths or missing authorization BEFORE connecting.
    production_check_location($config,realpath(__DIR__.'/..'),PHP_OS_FAMILY);
    $db=db(); production_require_environment($db,$config);
    if (in_array('--check',$argv,true)) {
        $status=['production_identity_verified'=>true,'application_root'=>realpath(__DIR__.'/..'),
            'database_name'=>$db->query('SELECT DATABASE()')->fetchColumn(),
            'release_complete'=>deployment_release_ready(),
            'administrators'=>production_admin_status($db),
            'email'=>email_diagnostic_status($db,email_config())];
        if (in_array('--verify-sender',$argv,true)) { $status['email']['provider_sender_status']=brevo_sender_status(email_config()); }
    } else {
        // No page visit activates email or sends a test; only this authorized job.
        $status=['administrators'=>production_provision($db,$config)];
        if ($config['PP_ACTIVATE_EMAIL']) {
            production_email_config($config);
            if (!deployment_release_ready()) { throw new RuntimeException('Deploy the complete reviewed release before email activation.'); }
            $done='production-email-setup:v1';
            if (subscription_query($db,'SELECT 1 FROM deployment_runs WHERE run_key=?',[$done])->fetchColumn()) { $status['email']='setup_already_attempted'; }
            else {
                email_activate($db);
                $status['email']=email_send_diagnostic($db,production_email_config($config),$config,true);
                // The send helper also holds its own durable attempt marker.
                subscription_query($db,'INSERT IGNORE INTO deployment_runs (run_key,details) VALUES (?,?)',[$done,json_encode($status['email'],JSON_THROW_ON_ERROR)]);
                $status['delivery']=email_diagnostic_delivery($db,email_config(),$config,true);
            }
        } else { $status['email']='activation_not_authorized'; }
    }
    echo json_encode($status,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR,'Production setup stopped: '.($e instanceof PDOException?'Database schema or authorization is unavailable; no credentials are logged.':$e->getMessage()).PHP_EOL); exit(1); }
