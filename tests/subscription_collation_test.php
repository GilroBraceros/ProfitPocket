<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(403);exit;}
require __DIR__.'/../includes/subscriptions.php';
require __DIR__.'/../includes/auth.php';
require __DIR__.'/../includes/admin.php';

// Simulate a server giving parameters and statement literals different
// connection collations. Use real prepared SQL and rollback-only fake accounts.
class ReviewCollationConnection extends PDO
{
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        $review=str_starts_with($query,'UPDATE subscription_payments SET ');
        if ($review) {$this->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');}
        $statement=parent::prepare($query,$options);
        if ($review) {$this->exec('SET NAMES utf8mb4 COLLATE utf8mb4_general_ci');}
        return $statement;
    }
}
function collation_expect(bool $ok,string $message): void {if (!$ok) {throw new RuntimeException($message);}}
function collation_counts(PDO $db): array
{
    $counts=[];
    foreach (['users','businesses','owner_subscriptions','subscription_payments','subscription_payment_methods','admin_action_logs','activity_logs','notification_outbox'] as $table) {
        $counts[$table]=(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
    }
    return $counts;
}
$previous=getenv('BREVO_EMAIL_ENABLED');putenv('BREVO_EMAIL_ENABLED=false');$db=null;
try {
    $config=database_config();
    $db=new ReviewCollationConnection('mysql:host='.$config['PP_DB_HOST'].';port='.$config['PP_DB_PORT'].';dbname='.$config['PP_DB_NAME'].';charset=utf8mb4',$config['PP_DB_USER'],$config['PP_DB_PASSWORD'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    database_initialize_session($db);
    collation_expect($db->query('SELECT @@collation_connection')->fetchColumn()==='utf8mb4_unicode_ci','Database session collation was not initialized.');
    $before=collation_counts($db);$db->beginTransaction();$suffix=bin2hex(random_bytes(7));
    $role=subscription_query($db,"SELECT role_id FROM roles WHERE role_code='platform_admin'")->fetchColumn();
    subscription_query($db,'INSERT INTO users(role_id,full_name,username,email,password_hash) VALUES(?,?,?,?,?)',[$role,'Collation test administrator','collation_admin_'.$suffix,'collation_admin_'.$suffix.'@example.invalid',password_hash('Temporary-collation-test-123!',PASSWORD_DEFAULT)]);$actor=(int)$db->lastInsertId();
    $owner=create_owner($db,['full_name'=>'Collation test owner','username'=>'collation_owner_'.$suffix,'email'=>'collation_owner_'.$suffix.'@example.invalid','business_name'=>'Collation rollback fixture','business_category_id'=>(string)$db->query('SELECT business_category_id FROM business_categories LIMIT 1')->fetchColumn()],'Temporary-collation-test-123!',false);
    subscription_save_method($db,$actor,0,['method_name'=>'Test wallet '.$suffix,'recipient_name'=>'Fictional test account','account_identifier'=>'TEST-'.$suffix,'instructions'=>'Rollback fixture only.','is_enabled'=>'1']);
    // The audit insert also uses AUTO_INCREMENT; obtain the method by its test ID.
    $method=(int)subscription_query($db,'SELECT method_id FROM subscription_payment_methods WHERE account_identifier=?',['TEST-'.$suffix])->fetchColumn();
    $proof=static fn(int $n)=>['name'=>hash('sha256','name-'.$suffix.$n).'.bin','hash'=>hash('sha256','content-'.$suffix.$n),'mime'=>'image/png','bytes'=>100];
    $first=subscription_submit($db,$owner,['method_id'=>$method,'reference_number'=>'TEST-FIRST-'.$suffix],$proof(1),bin2hex(random_bytes(32)));$id=(int)$first['id'];
    $reference=subscription_query($db,'SELECT reference_key FROM subscription_payments WHERE submission_id=?',[$id])->fetchColumn();
    $old=$db->prepare("UPDATE subscription_payments SET reference_key=CASE WHEN ?='rejected' THEN NULL ELSE reference_key END WHERE submission_id=?");
    try {$old->execute(['approved',$id]);throw new LogicException('Original collation failure was not reproduced.');}
    catch (PDOException $e) {collation_expect((int)($e->errorInfo[1]??0)===1267,'Unexpected original-query failure.');}
    $now=new DateTimeImmutable('now',new DateTimeZone('Asia/Manila'));
    subscription_review($db,$actor,$id,'approved','Verified rollback fixture',true,$now);
    $approved=subscription_query($db,'SELECT status,reference_key FROM subscription_payments WHERE submission_id=?',[$id])->fetch();
    $state=subscription_state($db,$owner,$now);$end=$state['expires_at'];
    collation_expect($approved['status']==='approved' && $approved['reference_key']===$reference && $state['active'],'Approval failed or changed the payment reference.');
    collation_expect(subscription_review($db,$actor,$id,'approved','Verified rollback fixture',true,$now)['replayed'] && subscription_state($db,$owner,$now)['expires_at']===$end,'Approval replay granted time twice.');
    $second=subscription_submit($db,$owner,['method_id'=>$method,'reference_number'=>'TEST-SECOND-'.$suffix],$proof(2),bin2hex(random_bytes(32)));
    subscription_review($db,$actor,(int)$second['id'],'rejected','Fictional receipt does not match.',false,$now);
    $rejected=subscription_query($db,'SELECT status,reference_key FROM subscription_payments WHERE submission_id=?',[$second['id']])->fetch();
    collation_expect($rejected['status']==='rejected' && $rejected['reference_key']===null && subscription_state($db,$owner,$now)['expires_at']===$end,'Rejection retained its reference or removed existing paid access.');
    $db->rollBack();collation_expect(collation_counts($db)===$before,'Collation test retained rows.');
    echo "PASS: original approval CASE reproduces error 1267; fixed approval/rejection succeed with mismatched parameter/literal collations; reference preservation/release, paid access and replay verified; all fixtures rolled back and no emails sent.\n";
} finally {
    if ($db && $db->inTransaction()) {$db->rollBack();}
    putenv($previous===false?'BREVO_EMAIL_ENABLED':'BREVO_EMAIL_ENABLED='.$previous);
}
