<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require __DIR__.'/../includes/production.php';
require __DIR__.'/../includes/auth.php';
require __DIR__.'/../includes/email_diagnostics.php';
require_once __DIR__.'/../includes/passwords.php';

function production_expect(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function production_reject(callable $call): void {
    try { $call(); } catch (RuntimeException $e) { return; }
    throw new LogicException('An unsafe production operation was accepted.');
}
function production_counts(PDO $db): array {
    $result=[];
    foreach (['users','businesses','owner_subscriptions','activity_logs','auth_sessions','deployment_runs','notification_outbox'] as $table) { $result[$table]=(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(); }
    return $result;
}

$suffix=bin2hex(random_bytes(7)); $temporary=sys_get_temp_dir().'/pp-production-'.$suffix;
$environment=[]; $testKeys=[]; $failed=false;
$db=db(); $before=production_counts($db); $realEmailPath=email_config_location()[1];
try {
    $settings=upgrade_config(); $settings['PP_ENVIRONMENT']='production';
    $settings['PP_PRODUCTION_APP_ROOT']='/home/u_test/domains/profitpocket.online/public_html/profitpocket';
    $settings['PP_PRODUCTION_DB_NAME']='u_test_profitpocket'; $settings['PP_PRODUCTION_DB_USER']='u_test_pp';
    $settings['PP_PRODUCTION_BASE_URL']='https://profitpocket.online/profitpocket';
    $settings['PP_PROVISION_TOKEN']=str_repeat('a',64); $settings['PP_PROVISION_TEST_ADMINS']=true;
    production_check_location($settings,$settings['PP_PRODUCTION_APP_ROOT'],'Linux');
    production_reject(fn()=>production_check_location($settings,$settings['PP_PRODUCTION_APP_ROOT'],'Windows'));
    production_reject(fn()=>production_check_location($settings,'C:/xampp/htdocs/profitpocket','Linux'));
    production_reject(fn()=>production_check_location($settings,'/home/u_test/domains/thesis.example/public_html','Linux'));
    production_reject(fn()=>production_check_location(array_replace($settings,['PP_ENVIRONMENT'=>'development']),$settings['PP_PRODUCTION_APP_ROOT'],'Linux'));
    production_reject(fn()=>production_check_location(array_replace($settings,['PP_PROVISION_TOKEN'=>'']),$settings['PP_PRODUCTION_APP_ROOT'],'Linux'));
    production_reject(fn()=>production_check_location(array_replace($settings,['PP_PRODUCTION_DB_NAME'=>'profitpocket_db']),$settings['PP_PRODUCTION_APP_ROOT'],'Linux'));
    production_expect(production_database_matches($settings,['database_name'=>'u_test_profitpocket','database_account'=>'u_test_pp@localhost']),'Hosted database identity rejected.');
    production_expect(!production_database_matches($settings,['database_name'=>'thesis_db','database_account'=>'u_test_pp@localhost']),'Wrong database accepted.');
    production_expect(!production_database_matches($settings,['database_name'=>'u_test_profitpocket','database_account'=>'root@localhost']),'Wrong database account accepted.');
    $releaseRoot='/home/u123456789/domains/profitpocket.online/public_html/profitpocket';
    production_expect(production_release_location($releaseRoot,'Linux'),'Designated source deployment rejected.');
    production_expect(!production_release_location($releaseRoot,'Windows') && !production_release_location('/home/u123456789/domains/thesis.example/public_html/profitpocket','Linux'),'Source bootstrap accepts local/unrelated installation.');
    $releaseSettings=['PP_DB_NAME'=>'u123456789_profitpocket','PP_DB_USER'=>'u123456789_pp'];
    production_expect(production_release_database_matches($releaseSettings,['database_name'=>'u123456789_profitpocket','database_account'=>'u123456789_pp@localhost']),'Existing Hostinger configuration rejected.');
    production_expect(!production_release_database_matches($releaseSettings,['database_name'=>'other_db','database_account'=>'u123456789_pp@localhost']),'Source bootstrap accepts a different database.');
    production_expect(!production_release_database_matches(array_replace($releaseSettings,['PP_DB_USER'=>'u999999999_pp']),['database_name'=>'u123456789_profitpocket','database_account'=>'u999999999_pp@localhost']),'Source bootstrap accepts another hosting account.');
    production_expect(!production_release_database_matches(['PP_DB_NAME'=>'profitpocket_db','PP_DB_USER'=>'root'],['database_name'=>'profitpocket_db','database_account'=>'root@localhost']),'Source bootstrap accepts XAMPP defaults.');
    production_expect(production_release_provision()==='not_applicable','Source bootstrap ran locally.');
    $_SERVER['HTTP_HOST']='profitpocket.online';
    production_reject(fn()=>production_provision($db,$settings));
    $selected=require __DIR__.'/../includes/production_test_accounts.php';
    foreach ($selected as $account) {
        production_expect(!subscription_query($db,'SELECT 1 FROM users WHERE email=?',[$account['email']])->fetchColumn(),'A selected production account exists locally.');
        production_expect(!isset($account['password']) && password_get_info($account['password_hash'])['algoName']!=='unknown','Plaintext or invalid deployed password.');
    }
    echo "PASS: explicit production identity, Linux/path/database/account checks, Host-header rejection and no selected accounts created in XAMPP.\n";

    mkdir($temporary,0700); $private=$temporary.'/database.php';
    $lineFixture=$temporary.'/lines.txt'; file_put_contents($lineFixture,"first\r\nsecond\r\n"); $normalizedHash=deployment_file_hash($lineFixture,true);
    file_put_contents($lineFixture,"first\nsecond\n"); production_expect(deployment_file_hash($lineFixture,true)===$normalizedHash,'Git line-ending conversion breaks deployment.');
    file_put_contents($lineFixture,"first\nchanged\n"); production_expect(deployment_file_hash($lineFixture,true)!==$normalizedHash,'Content tampering ignored by release check.'); unlink($lineFixture);
    file_put_contents($private,"<?php\nreturn ['PP_DB_HOST'=>'localhost','PP_DB_NAME'=>'u_test_private','PP_DB_USER'=>'u_test_reader','PP_DB_PASSWORD'=>'fabricated-private-fixture'];\n");
    $set=static function(string $key,string $value) use (&$environment): void { if (!array_key_exists($key,$environment)) { $environment[$key]=getenv($key); } putenv($key.'='.$value); };
    $set('PROFITPOCKET_DATABASE_CONFIG',$private);
    production_expect(database_config()['PP_DB_NAME']==='u_test_private','Private database configuration not loaded.');
    $set('PROFITPOCKET_DATABASE_CONFIG',__DIR__.'/../config/database.example.php'); production_reject(fn()=>database_config());
    $set('PROFITPOCKET_DATABASE_CONFIG',$private);
    $set('PP_DB_NAME','override_test'); production_expect(database_config()['PP_DB_NAME']==='override_test','Database environment precedence lost.');
    $set('PP_DB_NAME','unsafe;dbname=another'); production_reject(fn()=>database_config());
    foreach (['PROFITPOCKET_DATABASE_CONFIG','PP_DB_NAME'] as $key) { putenv($environment[$key]===false?$key:$key.'='.$environment[$key]); unset($environment[$key]); }
    echo "PASS: shared private database settings, environment precedence and unsafe DSN rejection; live connection untouched.\n";

    $db->beginTransaction();
    $bootstrap=upgrade_config(); $bootstrap['PP_BOOTSTRAP_AUTHORIZED']=true; $bootstrap['PP_BOOTSTRAP_TOKEN']=str_repeat('b',64);
    $accounts=[]; $passwords=[];
    foreach ([1,2] as $n) {
        $passwords[$n]='Rollback-only-'.$n.'-'.$suffix.'!';
        $accounts[]=['name'=>'Rollback test administrator '.$n,'email'=>'prod_fixture_'.$n.'_'.$suffix.'@example.invalid','password_hash'=>password_hash($passwords[$n],PASSWORD_DEFAULT)];
    }
    $key='bootstrap:test-hashed-'.$suffix;
    production_expect(deployment_bootstrap($db,$bootstrap,$accounts,$key)==='completed','Hashed bootstrap failed.');
    $hashes=[];
    foreach ($accounts as $i=>$account) {
        $user=find_login_user($db,$account['email']); $hashes[$i]=$user['password_hash'];
        production_expect($user['role_type']==='admin' && $user['account_status']==='active' && password_matches($user,$passwords[$i+1]),'Provisioned fixture cannot authenticate.');
        production_expect(bootstrap_needs_password_change($db,(int)$user['user_id']) && home_path($user)==='auth/change_password.php','Initial password gate missing.');
        production_expect(subscription_has_access($db,$user),'Administrator not subscription-exempt.');
        create_login_session($db,$user,$passwords[$i+1]);
    }
    production_expect(deployment_bootstrap($db,$bootstrap,$accounts,$key)==='already_completed','Repeat deployment duplicated accounts.');
    foreach ($accounts as $i=>$account) { production_expect(find_login_user($db,$account['email'])['password_hash']===$hashes[$i],'Repeat deployment changed password.'); }
    production_reject(fn()=>deployment_bootstrap($db,$bootstrap,$accounts,'bootstrap:collision-'.$suffix));
    $owner=create_owner($db,['full_name'=>'Rollback collision owner','username'=>'prod_owner_'.$suffix,'email'=>'prod_owner_'.$suffix.'@example.invalid','business_name'=>'Rollback business','business_category_id'=>(string)$db->query('SELECT business_category_id FROM business_categories LIMIT 1')->fetchColumn()],'Rollback-owner-123!',false);
    $conflict=$accounts; $conflict[0]['email']='prod_owner_'.$suffix.'@example.invalid';
    production_reject(fn()=>deployment_bootstrap($db,$bootstrap,$conflict,'bootstrap:owner-collision-'.$suffix));
    production_expect(find_login_user($db,$conflict[0]['email'])['role_type']==='owner','Owner promoted.');

    // Simulate the original schema without DDL. This uncommitted marker deletion
    // is invisible to other connections and is restored by the outer rollback.
    subscription_query($db,'DELETE FROM deployment_runs WHERE run_key=?',['migration:'.SUBSCRIPTION_VERSION]);
    production_expect(!subscription_schema($db),'Base-schema simulation failed.');
    $sourceAccounts=$accounts;
    foreach ($sourceAccounts as $i=>&$account) { $account['email']='source_fixture_'.$i.'_'.$suffix.'@example.invalid'; } unset($account);
    $sourceKey='bootstrap:source-test-'.$suffix;
    $subscriptionCount=(int)$db->query('SELECT COUNT(*) FROM owner_subscriptions')->fetchColumn();
    production_expect(deployment_bootstrap($db,$bootstrap,$sourceAccounts,$sourceKey,true)==='completed','Base-schema bootstrap failed.');
    foreach ($sourceAccounts as $i=>$account) {
        $user=find_login_user($db,$account['email']);
        production_expect(password_matches($user,$passwords[$i+1]) && bootstrap_needs_password_change($db,(int)$user['user_id']) && home_path($user)==='auth/change_password.php','Base-schema authentication/password gate failed.');
    }
    $user=find_login_user($db,$sourceAccounts[0]['email']);
    password_authenticated_change($db,(int)$user['user_id'],$passwords[1],'Changed-source-password-'.$suffix.'!','Changed-source-password-'.$suffix.'!',bin2hex(random_bytes(32)));
    $newHash=find_login_user($db,$sourceAccounts[0]['email'])['password_hash'];
    production_expect(!bootstrap_needs_password_change($db,(int)$user['user_id']),'Base-schema first-password marker not cleared.');
    production_expect(deployment_bootstrap($db,$bootstrap,$sourceAccounts,$sourceKey,true)==='already_completed' && find_login_user($db,$sourceAccounts[0]['email'])['password_hash']===$newHash,'Source rerun changed a password.');
    production_expect((int)$db->query('SELECT COUNT(*) FROM owner_subscriptions')->fetchColumn()===$subscriptionCount,'Base bootstrap wrote a subscription.');
    $db->rollBack(); production_expect(production_counts($db)===$before,'Bootstrap fixtures retained.');
    echo "PASS: two hashed rollback accounts, active administrator role, real authentication/session, first-password gate, subscription exemption, collision protection and idempotence; all fixtures rolled back.\n";
    echo "PASS: source-authorized deployment location/connection guards, original-schema activity ledger, mandatory password change and repeat protection without migrations or local provisioning.\n";

    $email=['BREVO_EMAIL_ENABLED'=>false,'BREVO_API_KEY'=>'fabricated-production-test-credential','BREVO_SENDER_EMAIL'=>'notify@profitpocket.online','BREVO_REPLY_TO_EMAIL'=>'support@profitpocket.online','BREVO_SENDER_NAME'=>'ProfitPocket','PROFITPOCKET_BASE_URL'=>'https://profitpocket.online/profitpocket','PROFITPOCKET_NOTIFICATION_SECRET'=>str_repeat('c',64)];
    $emailFile=$temporary.'/email.php'; file_put_contents($emailFile,"<?php\nreturn ".var_export($email,true).";\n");
    $set('PROFITPOCKET_EMAIL_CONFIG',$emailFile);
    foreach (array_keys($email) as $key) { if (!array_key_exists($key,$environment)) { $environment[$key]=getenv($key); } putenv($key); }
    $sender=fn()=>['http'=>200,'body'=>'{"senders":[{"email":"notify@profitpocket.online","active":true}]}'];
    email_activate($db,$sender); production_expect(email_config()['BREVO_EMAIL_ENABLED']===true,'Private activation failed.');
    production_expect(email_config()['BREVO_API_KEY']===$email['BREVO_API_KEY'],'Activation changed the key.');
    $email=email_config(); $test=['PP_EMAIL_TEST_AUTHORIZED'=>true,'PP_EMAIL_TEST_ADDRESS'=>'setup_fixture_'.$suffix.'@profitpocket.online'];
    production_expect(production_email_config($settings)['PROFITPOCKET_BASE_URL']===$settings['PP_PRODUCTION_BASE_URL'],'Production links rejected.');
    $set('PROFITPOCKET_BASE_URL','https://wrong.example.test'); production_reject(fn()=>production_email_config($settings)); putenv('PROFITPOCKET_BASE_URL');
    $testKeys[]=email_diagnostic_key($test['PP_EMAIL_TEST_ADDRESS'],true); $sent=0;
    $mock=function(array $payload) use (&$sent): array {
        $sent++; production_expect(str_contains($payload['htmlContent'],'ProfitPocket') && !preg_match('/href="http:/',$payload['htmlContent']),'Invalid diagnostic branding/links.');
        production_expect(!isset($payload['api-key']) && !str_contains(json_encode($payload),'fabricated-production-test-credential'),'Secret in email payload.');
        return ['http'=>201,'body'=>'{"messageId":"<production-fixture@example.invalid>"}'];
    };
    production_expect(email_send_diagnostic($db,$email,$test,true,$mock,$sender)['status']==='accepted','Mock acceptance missing.');
    production_expect(email_send_diagnostic($db,$email,$test,true,$mock,$sender)['replayed'] && $sent===1,'Test email replayed.');
    production_expect(email_diagnostic_key($test['PP_EMAIL_TEST_ADDRESS'])!==$testKeys[0],'Local and hosted diagnostic markers overlap.');
    $report=fn()=>['http'=>200,'body'=>json_encode(['events'=>[['messageId'=>'<production-fixture@example.invalid>','email'=>$test['PP_EMAIL_TEST_ADDRESS'],'event'=>'delivered']]])];
    $delivery=email_diagnostic_delivery($db,$email,$test,true,$report);
    production_expect($delivery['provider_delivery']==='delivered' && $delivery['inbox_delivery']==='not_verified','Provider delivery/inbox distinction lost.');
    $wrong=fn()=>['http'=>200,'body'=>'{"events":[{"messageId":"<unrelated>","email":"someone@profitpocket.online","event":"delivered"}]}'];
    production_expect(email_diagnostic_delivery($db,$email,$test,true,$wrong)['provider_delivery']==='not_yet_reported','Unrelated delivery event accepted.');
    $test['PP_EMAIL_TEST_ADDRESS']='uncertain_fixture_'.$suffix.'@profitpocket.online'; $testKeys[]=email_diagnostic_key($test['PP_EMAIL_TEST_ADDRESS'],true);
    $uncertain=0; $timeout=function() use (&$uncertain): array {$uncertain++; return ['error'=>28];};
    production_expect(email_send_diagnostic($db,$email,$test,true,$timeout,$sender)['status']==='uncertain','Timeout outcome was misrepresented.');
    production_expect(email_send_diagnostic($db,$email,$test,true,$timeout,$sender)['replayed'] && $uncertain===1,'Uncertain test was resent.');
    echo "PASS: activation writes only disposable private settings, one mocked send, durable repeat/timeout protection, hosted/local markers, correct branding/HTTPS and message-specific provider delivery checks. No real Brevo calls.\n";
} catch (Throwable $e) { if ($db->inTransaction()) {$db->rollBack();} fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL); $failed=true; }
finally {
    foreach ($testKeys as $key) { subscription_query($db,'DELETE FROM deployment_runs WHERE run_key=?',[$key]); }
    foreach ($environment as $key=>$value) { putenv($value===false?$key:$key.'='.$value); }
    foreach ([$temporary.'/database.php',$temporary.'/email.php',$temporary.'/lines.txt'] as $file) { if (is_file($file)) {unlink($file);} }
    if (is_dir($temporary)) {rmdir($temporary);}
}
production_expect(email_config_location()[1]===$realEmailPath && production_counts($db)===$before,'Real configuration or database state changed.');
if ($failed) {exit(1);}
