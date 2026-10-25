<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(403);exit;}
require __DIR__.'/../config/paths.php';
require __DIR__.'/../config/database_bootstrap.php';
function route_expect(bool $ok,string $message): void {if(!$ok){throw new RuntimeException($message);}}
$root=sys_get_temp_dir().'/pp-routing-'.bin2hex(random_bytes(8));mkdir($root,0700);
try {
    foreach (['auth/login.php','auth/register.php','auth/change_password.php','user/dashboard.php','admin/subscriptions.php','index.php'] as $page) {
        route_expect(profitpocket_base_path(['SCRIPT_NAME'=>'/'.$page])==='','Root routing failed.');
        route_expect(profitpocket_base_path(['SCRIPT_NAME'=>'/profitpocket/'.$page])==='/profitpocket','Subfolder routing changed.');
    }
    route_expect(profitpocket_base_path(['SCRIPT_NAME'=>'/profitpocket/auth/login.php'],'')==='','Explicit root override ignored.');
    route_expect(profitpocket_base_path(['SCRIPT_NAME'=>'/auth/login.php'],'/proxy-app/')==='/proxy-app','Explicit proxy override ignored.');
    foreach (['//external.example','/../escape',"/bad\r\nheader"] as $invalid) {
        try {profitpocket_base_path([], $invalid);throw new LogicException('Unsafe base path accepted.');}catch(RuntimeException $e){}
    }
    $hosted='/home/u123456789/domains/profitpocket.online/public_html';
    route_expect(database_legacy_allowed($hosted,'Linux',false,[]),'Eligible existing connection denied.');
    route_expect(!database_legacy_allowed($hosted,'Windows',false,[]),'Local connection import enabled.');
    route_expect(!database_legacy_allowed('/home/u123456789/domains/thesis.example/public_html','Linux',false,[]),'Unrelated website eligible.');
    route_expect(!database_legacy_allowed($hosted,'Linux',true,[]),'Private configuration overwritten.');
    route_expect(!database_legacy_allowed($hosted,'Linux',false,['PP_DB_PASSWORD'=>'']),'Environment configuration overwritten.');
    $helper=realpath(__DIR__.'/../config/database_bootstrap.php');
    foreach (['older'=>"<?php echo 'must-not-leak'; function db(): string { return 'original-connection'; }",'modern'=>"<?php function database_config(): array {return [];} function db(): string{return 'wrong';}"] as $kind=>$source) {
        $file=$root.'/'.$kind.'.php';file_put_contents($file,$source);
        $code='<?php require '.var_export($helper,true).'; $loaded=database_import_legacy('.var_export($file,true).'); echo json_encode(["loaded"=>$loaded,"db_exists"=>function_exists("db"),"same_source"=>$loaded && realpath((new ReflectionFunction("db"))->getFileName())===realpath('.var_export($file,true).')]);';
        $process=proc_open([PHP_BINARY],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fwrite($pipes[0],$code);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);$status=proc_close($process);
        route_expect($status===0 && !str_contains($out,'must-not-leak'),'Existing include leaked output or failed.');$result=json_decode($out,true);
        route_expect($kind==='older'?($result['loaded'] && $result['same_source']):(!$result['loaded'] && !$result['db_exists']),'Existing connection selection failed.');
    }
    echo "PASS: root/subfolder/proxy routes, safe base paths, local/unrelated exclusion, explicit settings precedence and isolated existing-connection reuse without credential output.\n";
} finally {foreach(glob($root.'/*.php')?:[] as $file){unlink($file);}rmdir($root);}
