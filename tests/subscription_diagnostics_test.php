<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(403);exit;}
require __DIR__.'/../includes/subscription_diagnostics.php';

class SubscriptionDiagnosticStatement extends PDOStatement
{
    public function __construct(private bool $recorded) {}
    public function execute(?array $params=null): bool {return true;}
    public function fetchColumn(int $column=0): mixed {return $this->recorded?1:false;}
}
class SubscriptionDiagnosticConnection extends PDO
{
    public array $queries=[];
    public function __construct(private array $failures=[],private bool $recorded=true) {}
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        $this->queries[]=$query;
        if (!str_starts_with($query,'SELECT ')) {throw new LogicException('Diagnosis attempted a write.');}
        foreach ($this->failures as $table=>$number) {
            if (str_contains($query,'FROM '.$table.' ')) {
                $e=new PDOException('SECRET_PASSWORD private-host /private/config/path');
                $e->errorInfo=['HY000',$number,'SECRET_PASSWORD'];
                throw $e;
            }
        }
        return new SubscriptionDiagnosticStatement($this->recorded);
    }
}
function diagnosis_expect(bool $ok,string $message): void {if (!$ok) {throw new RuntimeException($message);}}
$config=tempnam(sys_get_temp_dir(),'pp-diagnosis-');
$previous=getenv('PROFITPOCKET_UPGRADE_CONFIG');
try {
    file_put_contents($config,"<?php return [];\n");
    putenv('PROFITPOCKET_UPGRADE_CONFIG='.$config);
    diagnosis_expect(subscription_admin_diagnosis(new SubscriptionDiagnosticConnection())===[],'Healthy setup reported a failure.');
    $issues=subscription_admin_diagnosis(new SubscriptionDiagnosticConnection([],false));
    diagnosis_expect(array_column($issues,'code')===['migration_not_recorded'],'Missing migration marker was not distinguished.');
    foreach ([1146=>'missing_table',1054=>'missing_columns',1142=>'database_permissions',2006=>'database_check_failed'] as $number=>$code) {
        $db=new SubscriptionDiagnosticConnection(['subscription_payments'=>$number]);
        $issues=subscription_admin_diagnosis($db);
        diagnosis_expect(array_column($issues,'code')===[$code],'Incorrect database failure classification.');
        diagnosis_expect(!str_contains(json_encode($issues),'SECRET_PASSWORD') && !str_contains(json_encode($issues),'private-host'),'Private exception details leaked.');
        diagnosis_expect(count($db->queries)===5,'Diagnosis skipped independent checks.');
    }
    $db=new SubscriptionDiagnosticConnection(['deployment_runs'=>1146]);
    diagnosis_expect(array_column(subscription_admin_diagnosis($db),'code')===['missing_table'] && count($db->queries)===4,'Missing deployment ledger caused a follow-up query.');
    $failure=new PDOException('SECRET_PASSWORD');$failure->errorInfo=['HY000',1267,'SECRET_PASSWORD'];
    diagnosis_expect(array_column(subscription_admin_diagnosis(new SubscriptionDiagnosticConnection(),$failure),'code')===['database_collation'],'Collation failure not distinguished.');
    $failure->errorInfo=['42000',1142,'SECRET_PASSWORD'];
    diagnosis_expect(array_column(subscription_admin_diagnosis(new SubscriptionDiagnosticConnection(['deployment_runs'=>1146]),$failure),'code')===['missing_table','database_permissions'],'Missing schema hid the actual CREATE/ALTER permission failure.');
    file_put_contents($config,"<?php return false;\n");
    diagnosis_expect(array_column(subscription_admin_diagnosis(new SubscriptionDiagnosticConnection()),'code')===['private_config_invalid'],'Invalid private configuration not distinguished.');
    echo "PASS: read-only setup checks, missing tables/columns/ledger, permissions, collation, private configuration and secret redaction.\n";
} finally {
    putenv($previous===false?'PROFITPOCKET_UPGRADE_CONFIG':'PROFITPOCKET_UPGRADE_CONFIG='.$previous);
    if (is_file($config)) {unlink($config);}
}
