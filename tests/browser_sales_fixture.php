<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require_once __DIR__.'/../config/database.php';
$db=db();
try {
    $db->beginTransaction();
    if (($argv[1]??'')==='create') {
        $username='modal_browser_'.bin2hex(random_bytes(12));
        $password=bin2hex(random_bytes(24));
        $role=$db->query("SELECT role_id FROM roles WHERE role_code='business_owner'")->fetchColumn();
        $category=$db->query('SELECT business_category_id FROM business_categories LIMIT 1')->fetchColumn();
        $db->prepare('INSERT INTO users (role_id,full_name,username,email,password_hash) VALUES (?,?,?,?,?)')->execute([$role,'Temporary browser owner',$username,$username.'@example.invalid',password_hash($password,PASSWORD_DEFAULT)]);
        $id=(int)$db->lastInsertId();
        $db->prepare('INSERT INTO businesses (owner_user_id,business_category_id,business_name) VALUES (?,?,?)')->execute([$id,$category,'Temporary browser business']);
        $business=(int)$db->lastInsertId();
        $db->prepare('INSERT INTO products (business_id,product_name,default_selling_price,default_unit_cost) VALUES (?,?,?,?)')->execute([$business,'Temporary browser product','125.50','50.00']);
        $db->commit();
        echo json_encode(['username'=>$username,'password'=>$password],JSON_THROW_ON_ERROR);
    } elseif (($argv[1]??'')==='cleanup' && preg_match('/\Amodal_browser_[a-f0-9]{24}\z/',$argv[2]??'')) {
        $query=$db->prepare('SELECT u.user_id,b.business_id FROM users u JOIN businesses b ON b.owner_user_id=u.user_id WHERE u.username=? FOR UPDATE'); $query->execute([$argv[2]]);
        $fixture=$query->fetch();
        if (!$fixture) { throw new RuntimeException('Browser fixture not found.'); }
        $business=$fixture['business_id'];
        $guard=$db->prepare("SELECT COUNT(*) FROM sales s WHERE s.business_id=? AND (s.sale_status<>'draft' OR EXISTS(SELECT 1 FROM sale_payments p WHERE p.sale_id=s.sale_id) OR EXISTS(SELECT 1 FROM customer_credits c WHERE c.sale_id=s.sale_id))"); $guard->execute([$business]);
        if ((int)$guard->fetchColumn()!==0) { throw new RuntimeException('Cleanup blocked: browser fixture has financial history.'); }
        $expenseGuard=$db->prepare('SELECT COUNT(*) FROM expenses WHERE business_id=?'); $expenseGuard->execute([$business]);
        if ((int)$expenseGuard->fetchColumn()!==0) { throw new RuntimeException('Cleanup blocked: browser fixture has expense history.'); }
        $db->prepare('DELETE FROM sale_items WHERE business_id=?')->execute([$business]);
        $db->prepare('DELETE FROM sales WHERE business_id=?')->execute([$business]);
        $db->prepare('DELETE FROM customers WHERE business_id=?')->execute([$business]);
        $db->prepare('DELETE FROM products WHERE business_id=?')->execute([$business]);
        $db->prepare('DELETE FROM activity_logs WHERE business_id=? OR actor_user_id=?')->execute([$business,$fixture['user_id']]);
        $db->prepare('DELETE FROM auth_sessions WHERE user_id=?')->execute([$fixture['user_id']]);
        $db->prepare('DELETE FROM businesses WHERE business_id=?')->execute([$business]);
        $db->prepare('DELETE FROM users WHERE user_id=?')->execute([$fixture['user_id']]);
        $db->commit();
        echo "PASS: exact temporary browser business, unpaid immediate drafts and related fixtures removed.\n";
    } else { throw new InvalidArgumentException('Use create, or cleanup with the exact generated fixture username.'); }
} catch (Throwable $exception) {
    if ($db->inTransaction()) { $db->rollBack(); }
    fwrite(STDERR,$exception->getMessage().PHP_EOL); exit(1);
}
