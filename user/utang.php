<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/sales.php';
$user=require_business();
if ($_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405); exit; }
$params=[];
foreach (['q','state','customer','sort','page','start','end'] as $key) { if (is_string($_GET[$key]??null)) { $params[$key]=$_GET[$key]; } }
$params['tab']='balances';
if (!empty($params['start']) && !empty($params['end'])) { $params['period']='custom'; }
redirect_to('user/payments.php?'.http_build_query($params));
