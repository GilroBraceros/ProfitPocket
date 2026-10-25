<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require __DIR__.'/../includes/deployment.php';
$db=db(); $locked=false;
try {
    $config=upgrade_config();
    if (!deployment_release_ready()) { throw new RuntimeException('Release files are incomplete or differ from the reviewed release manifest.'); }
    $lock='pp-upgrade:'.substr(hash('sha256',$db->query('SELECT DATABASE()')->fetchColumn()),0,32);
    $locked=(bool)subscription_query($db,'SELECT GET_LOCK(?,0)',[$lock])->fetchColumn();
    if (!$locked) { echo "Another deployment runner is active.\n"; exit; }
    $result=['migration'=>deployment_migrate($db,$config)];
    if (!in_array('--migrate-only',$argv,true)) { $result['bootstrap']=deployment_bootstrap($db,$config); }
    echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR,'Upgrade stopped: '.($e instanceof PDOException?'Database operation failed; inspect the reviewed schema and private configuration.':$e->getMessage()).PHP_EOL); exit(1); }
finally { if ($locked) { subscription_query($db,'SELECT RELEASE_LOCK(?)',[$lock]); } }
