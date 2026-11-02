<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(403);exit;}
require __DIR__.'/../includes/subscription_setup.php';

// Exercise the actual migration runner against a PDO double, never local DDL.
class SetupTestStatement extends PDOStatement
{
    private array $values=[];
    public function __construct(private SetupTestConnection $db,private string $sql) {}
    public function execute(?array $params=null): bool
    {
        $this->values=$params??[];
        if (str_contains($this->sql,'RELEASE_LOCK')) {$this->db->released=true;}
        if (str_starts_with($this->sql,'INSERT INTO deployment_runs')) {$this->db->runs[$this->values[0]]=$this->values[1];}
        return true;
    }
    public function fetchColumn(int $column=0): mixed
    {
        if (str_contains($this->sql,'GET_LOCK')) {return $this->db->lockAvailable?1:0;}
        if (str_contains($this->sql,'RELEASE_LOCK')) {$this->db->released=true;return 1;}
        if (str_contains($this->sql,'SELECT DATABASE()')) {return 'fictional_setup_test';}
        if (str_contains($this->sql,'information_schema.TABLES')) {return (int)isset($this->db->tables['notification_outbox'])+(int)isset($this->db->tables['customer_email_preferences']);}
        if (str_contains($this->sql,'information_schema.COLUMNS')) {
            preg_match("/COLUMN_NAME='([a-z_]+)'/",$this->sql,$columnMatch);
            preg_match("/TABLE_NAME='([a-z_]+)'/",$this->sql,$tableMatch);
            return in_array($columnMatch[1]??'', $this->db->tables[$tableMatch[1]??'']??[],true)?1:false;
        }
        if (str_contains($this->sql,'FROM deployment_runs')) {return $this->db->runs[$this->values[0]]??false;}
        return false;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed
    {
        return ['user_id'=>1,'role_id'=>1,'account_status'=>'active','role_type'=>$this->db->role];
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array
    {
        if (str_contains($this->sql,'information_schema.COLUMNS')) {return $this->db->tables[$this->values[0]]??[];}
        if (str_contains($this->sql,'role_permissions')) {return $this->db->permissions;}
        return [];
    }
}
class SetupTestConnection extends PDO
{
    public array $tables=[],$runs=[],$ddl=[],$permissions=['users.manage','settings.manage'];
    public string $role='admin';
    public bool $lockAvailable=true,$released=false,$transaction=false;
    public ?int $failDdl=null;
    public function __construct() {}
    public function prepare(string $query,array $options=[]): PDOStatement|false {return new SetupTestStatement($this,$query);}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false {return new SetupTestStatement($this,$query);}
    public function exec(string $statement): int|false
    {
        $statement=trim($statement);
        if ($this->failDdl!==null && count($this->ddl)===$this->failDdl) {throw new PDOException('Simulated interrupted DDL');}
        foreach (subscription_setup_files() as $file) {
            foreach (subscription_setup_statements($file) as $item) {
                if ($statement!==$item['sql']) {continue;}
                $this->ddl[]=$statement;
                if ($item['column']) {$this->tables[$item['table']][]=$item['column'];}
                else {$this->tables[$item['table']]??=$item['columns'];}
                $this->transaction=false;return 0;
            }
        }
        throw new LogicException('Unreviewed DDL executed.');
    }
    public function inTransaction(): bool {return $this->transaction;}
    public function beginTransaction(): bool {$this->transaction=true;return true;}
    public function commit(): bool {$this->transaction=false;return true;}
    public function rollBack(): bool {$this->transaction=false;return true;}
}
function setup_expect(bool $ok,string $message): void {if (!$ok) {throw new RuntimeException($message);}}
function setup_input(PDO $db): array
{
    return ['request_key'=>str_repeat('a',64),'approve_schema'=>'1','owner_policy'=>'pending-payment',
        'plan_hash'=>hash('sha256',json_encode(subscription_setup_plan($db),JSON_THROW_ON_ERROR))];
}
function setup_reject(SetupTestConnection $db,array $input,bool $csrf=true): void
{
    try {subscription_setup_apply($db,1,$input,$csrf,str_repeat('a',64));throw new LogicException('Unsafe setup request accepted.');}
    catch (InvalidArgumentException|AdminDenied $e) {setup_expect(!$db->ddl,'Rejected request changed schema.');}
}
$db=new SetupTestConnection();
$plan=subscription_setup_plan($db);setup_expect(count($plan)===11 && !$db->ddl,'Read-only plan must contain seven tables and four columns.');
$input=setup_input($db);
setup_reject($db,$input,false);
setup_reject($db,array_replace($input,['request_key'=>'wrong']));
setup_reject($db,array_replace($input,['approve_schema'=>'0']));
setup_reject($db,array_replace($input,['owner_policy'=>'grant-paid-time']));
setup_reject($db,array_replace($input,['plan_hash'=>'stale']));
$db->permissions=['users.manage'];setup_reject($db,$input);
$db->permissions=['users.manage','settings.manage'];$db->role='owner';setup_reject($db,$input);
$db->role='admin';$db->lockAvailable=false;setup_reject($db,$input);$db->lockAvailable=true;
$result=subscription_setup_apply($db,1,$input,true,str_repeat('a',64));
setup_expect($result['subscriptions']==='applied' && $result['registration_payments']==='applied' && count($db->ddl)===11 && $db->released,'Reviewed setup did not finish.');
$count=count($db->ddl);$result=subscription_setup_apply($db,1,setup_input($db),true,str_repeat('a',64));
setup_expect($result['subscriptions']==='already_applied' && count($db->ddl)===$count,'Setup replay applied duplicate DDL.');
$partial=new SetupTestConnection();$partial->failDdl=5;
try {subscription_setup_apply($partial,1,setup_input($partial),true,str_repeat('a',64));throw new LogicException('Expected interrupted DDL.');}
catch (PDOException $e) {setup_expect($partial->released && !$partial->runs,'Interrupted DDL incorrectly marked complete or leaked its lock.');}
$partial->failDdl=null;$result=subscription_setup_apply($partial,1,setup_input($partial),true,str_repeat('a',64));
setup_expect($result['registration_payments']==='applied' && subscription_setup_plan($partial)===[],'Interrupted setup did not resume.');
$bad=new SetupTestConnection();$bad->tables['notification_outbox']=['notification_id'];
try {subscription_setup_plan($bad);throw new LogicException('Incompatible existing table accepted.');}
catch (InvalidArgumentException $e) {setup_expect(!$bad->ddl,'Incompatible existing table changed.');}
echo "PASS: reviewed seven-table/four-column plan, CSRF/nonce/explicit approval/role/permission guards, stale-plan rejection, locking, completion/replay and interrupted-DDL recovery; no real database structure changed.\n";
