<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(403);exit;}
require __DIR__.'/../includes/subscription_email.php';

class DeliveryStatement extends PDOStatement
{
    private array $params=[];
    public function __construct(private DeliveryConnection $db,private string $sql) {}
    public function execute(?array $params=null): bool
    {
        $this->params=$params??[];
        if (str_contains($this->sql,'RELEASE_LOCK')) {$this->db->released=true;}
        if (str_starts_with($this->sql,"UPDATE notification_outbox SET status='processing'")) {$this->db->job['status']='processing';$this->db->job['attempts']++;}
        elseif (str_starts_with($this->sql,"UPDATE notification_outbox SET status='uncertain'")) {$this->db->job['status']='uncertain';}
        elseif (str_starts_with($this->sql,"UPDATE notification_outbox SET status='skipped'")) {$this->db->job['status']='skipped';$this->db->job['failure_code']=$this->params[0];}
        elseif (str_starts_with($this->sql,'UPDATE notification_outbox SET status=?')) {$this->db->job['status']=$this->params[0];$this->db->job['message_id']=$this->params[2];}
        return true;
    }
    public function fetchColumn(int $column=0): mixed {return str_contains($this->sql,'GET_LOCK')?(int)$this->db->available:'delivery-test-db';}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed
    {
        if (str_contains($this->sql,'FROM notification_outbox')) {
            if (($this->params[0]??0)!==$this->db->job['notification_id']) {return false;}
            if (str_contains($this->sql,"status='pending'") && $this->db->job['status']!=='pending') {return false;}
            return $this->db->job;
        }
        if (str_contains($this->sql,'FROM users u JOIN roles')) {return ['full_name'=>'Fictional test owner','email'=>'owner@example.invalid','account_status'=>'active'];}
        if (str_contains($this->sql,'FROM subscription_payments')) {return ['status'=>'approved','submitted_at'=>'2026-11-02 10:00:00','period_start'=>'2026-11-02 10:05:00','period_end'=>'2026-12-02 10:05:00','review_note'=>'Verified fixture'];}
        throw new LogicException('Unexpected delivery query.');
    }
}
class DeliveryConnection extends PDO
{
    public bool $transaction=false,$available=true,$released=false;
    public array $job;
    public function __construct() {$this->job=['notification_id'=>12,'notification_type'=>'subscription_approved','subscription_payment_id'=>8,'user_id'=>3,'event_key'=>hash('sha256','subscription-decision:8'),'status'=>'pending','attempts'=>0,'first_attempt_at'=>null,'due'=>1,'failure_code'=>null];}
    public function prepare(string $query,array $options=[]): PDOStatement|false {return new DeliveryStatement($this,$query);}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false {return new DeliveryStatement($this,$query);}
    public function inTransaction(): bool {return $this->transaction;}
}
function delivery_expect(bool $ok,string $message): void {if (!$ok) {throw new RuntimeException($message);}}
$config=['BREVO_EMAIL_ENABLED'=>true,'BREVO_API_KEY'=>'fabricated-delivery-credential-only','BREVO_SENDER_EMAIL'=>'notify@example.invalid','BREVO_SENDER_NAME'=>'ProfitPocket','BREVO_REPLY_TO_EMAIL'=>'','PROFITPOCKET_BASE_URL'=>'https://profitpocket.example.test','PROFITPOCKET_NOTIFICATION_SECRET'=>str_repeat('a',64)];
$previous=getenv('PP_SUBSCRIPTIONS_ENABLED');putenv('PP_SUBSCRIPTIONS_ENABLED=false');$calls=0;
$transport=static function(array $payload)use(&$calls):array {$calls++;delivery_expect($payload['to'][0]['email']==='owner@example.invalid' && str_contains($payload['subject'],'Active'),'Wrong recipient or approval template.');return ['http'=>201,'body'=>'{"messageId":"<delivery-test@example.invalid>"}'];};
try {
    $db=new DeliveryConnection();$db->transaction=true;
    try {notification_process_one($db,12,$config,$transport);throw new RuntimeException('Sent before commit.');}catch(LogicException $e){}
    delivery_expect($calls===0,'Pre-commit delivery called Brevo.');$db->transaction=false;
    delivery_expect(notification_process_one($db,12,array_replace($config,['BREVO_EMAIL_ENABLED'=>false]),$transport)==='disabled' && $calls===0,'Disabled configuration sent an email.');
    $db->available=false;delivery_expect(notification_process_one($db,12,$config,$transport)==='busy' && $calls===0,'Concurrent worker lock ignored.');$db->available=true;
    delivery_expect(notification_process_one($db,12,$config,$transport)==='accepted' && $calls===1 && $db->released,'Committed approval did not send while enforcement was off.');
    delivery_expect(notification_process_one($db,12,$config,$transport)==='accepted' && $calls===1,'Accepted event sent twice.');
    $db=new DeliveryConnection();$db->job['due']=0;delivery_expect(notification_process_one($db,12,$config,$transport)==='pending' && $calls===1,'Retry backoff ignored.');
    $db=new DeliveryConnection();$db->job['status']='processing';delivery_expect(notification_process_one($db,12,$config,$transport)==='uncertain' && $calls===1,'Unknown in-flight attempt was resent.');
    delivery_expect(notification_process_one($db,12,$config,$transport)==='uncertain' && $calls===1,'Uncertain attempt was resent.');
    $db=new DeliveryConnection();delivery_expect(notification_process_one($db,99,$config,$transport)==='not_queued' && $calls===1,'Unrelated job was processed.');
    $reminder=$db->job;$reminder['subscription_payment_id']=null;$reminder['notification_type']='subscription_expiring';
    try {subscription_notification($db,$reminder);throw new RuntimeException('Disabled-rollout reminder resolved.');}catch(DomainException $e){delivery_expect($e->getMessage()==='subscription_rollout_disabled','Wrong reminder guard.');}
    echo "PASS: post-commit single-event delivery, approval template with enforcement disabled, sending-disabled guard, cron locking, replay prevention, backoff, uncertain outcomes, unrelated-job isolation and scheduled-reminder rollout guard; no real email or database writes.\n";
} finally {putenv($previous===false?'PP_SUBSCRIPTIONS_ENABLED':'PP_SUBSCRIPTIONS_ENABLED='.$previous);}
