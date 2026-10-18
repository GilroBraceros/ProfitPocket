<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/expenses.php';
$user=require_business();
try {
    $id=sale_id(sale_text($_GET,'id')); $file=expense_receipt(db(),(int)$user['business_id'],$id);
    $extension=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$file['mime_type']];
    header('Content-Type: '.$file['mime_type']); header('Content-Length: '.$file['file_size_bytes']);
    header('Content-Disposition: '.(($_GET['download']??'')==='1'?'attachment':'inline').'; filename="receipt-'.$id.'.'.$extension.'"');
    header("Content-Security-Policy: default-src 'none'; sandbox; frame-ancestors 'none'");
    session_write_close(); readfile($file['path']);
} catch (ExpenseNotFound | InvalidArgumentException $exception) { http_response_code(404); header('Content-Type: text/plain; charset=UTF-8'); echo 'Receipt not found.';
} catch (Throwable $exception) { error_log('ProfitPocket: receipt read failed.'); http_response_code(503); header('Content-Type: text/plain; charset=UTF-8'); echo 'Receipt temporarily unavailable.'; }
