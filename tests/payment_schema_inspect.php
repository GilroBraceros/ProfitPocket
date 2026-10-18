<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__.'/../config/database.php';
$db=db();
$schema=$db->query('SELECT DATABASE()')->fetchColumn();
$columns=$db->prepare('SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME,ORDINAL_POSITION');
$columns->execute([$schema]);
$keys=$db->prepare('SELECT TABLE_NAME,COLUMN_NAME,CONSTRAINT_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION');
$keys->execute([$schema]);
$views=$db->prepare('SELECT TABLE_NAME,VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA=?');
$views->execute([$schema]);
$result=['columns'=>$columns->fetchAll(),'keys'=>$keys->fetchAll(),'views'=>$views->fetchAll(),'methods'=>$db->query('SELECT * FROM payment_methods ORDER BY payment_method_id')->fetchAll()];
$objects=$db->prepare('SELECT TABLE_TYPE,COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA=? GROUP BY TABLE_TYPE'); $objects->execute([$schema]);
$result['objects']=array_column($objects->fetchAll(),'total','TABLE_TYPE');
$triggers=$db->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=?'); $triggers->execute([$schema]); $result['triggers']=(int)$triggers->fetchColumn();
$result['remaining_payment_browser_fixtures']=(int)$db->query("SELECT COUNT(*) FROM users WHERE email LIKE '%@example.invalid' AND (username LIKE 'payment_test_%' OR username LIKE 'modal_browser_%')")->fetchColumn();
file_put_contents(__DIR__.'/http-results/payment-schema-inspection.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode(['methods'=>$result['methods'],'objects'=>$result['objects'],'triggers'=>$result['triggers'],'remaining_payment_browser_fixtures'=>$result['remaining_payment_browser_fixtures'],'payment_columns'=>array_values(array_filter($result['columns'],fn($c)=>$c['TABLE_NAME']==='sale_payments'))],JSON_PRETTY_PRINT).PHP_EOL;
