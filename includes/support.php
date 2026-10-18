<?php
declare(strict_types=1);
require_once __DIR__.'/sales.php';
require_once __DIR__.'/platform.php';

const SUPPORT_ACTIONS = [
    'announcement'=>['Announcement',false],
    'ticket'=>['Support ticket',false],
    'new_ticket'=>['New support ticket',true],
    'reply'=>['Add a follow-up',true],
    'feedback'=>['Share feedback',true],
];
function support_text(array $input, string $key, int $limit, bool $required = true): string
{
    $value = sale_text($input, $key);
    if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value)>$limit || ($required && $value==='')) {
        throw new InvalidArgumentException('Enter '.str_replace('_',' ',$key).' within '.$limit.' characters.');
    }
    return $value;
}
function support_ticket(PDO $db, int $owner, int $id, bool $lock = false): array
{
    $row = platform_query($db, 'SELECT ticket_id,subject,description,priority,ticket_status,created_at,updated_at,resolved_at
        FROM support_tickets WHERE ticket_id=? AND submitted_by=?'.($lock?' FOR UPDATE':''), [$id,$owner])->fetch();
    if (!$row) { throw new OutOfBoundsException('Ticket not found.'); }
    $row['messages'] = platform_query($db, 'SELECT m.message_body,m.created_at,u.full_name,r.role_type
        FROM ticket_messages m JOIN users u ON u.user_id=m.sender_user_id JOIN roles r ON r.role_id=u.role_id
        WHERE m.ticket_id=? ORDER BY m.created_at,m.message_id', [$id])->fetchAll();
    $row['history'] = platform_query($db, 'SELECT old_status,new_status,changed_at FROM ticket_status_history
        WHERE ticket_id=? ORDER BY changed_at,history_id', [$id])->fetchAll();
    return $row;
}
function support_listing(PDO $db, int $owner, array $input): array
{
    $tab = sale_text($input,'tab','tickets');
    $state = sale_text($input,'state','all');
    $page = sale_text($input,'page','1');
    if (!in_array($tab,['tickets','feedback'],true) || !ctype_digit($page) || strlen($page)>6 || (int)$page<1) {
        throw new InvalidArgumentException('Choose a valid page.');
    }
    $states = $tab==='tickets'?['open','in_progress','resolved','closed']:['new','reviewed','archived'];
    if (!in_array($state,array_merge(['all'],$states),true)) { throw new InvalidArgumentException('Choose a valid status.'); }
    $source = $tab==='tickets'?'FROM support_tickets WHERE submitted_by=?':'FROM user_feedback WHERE submitted_by=?';
    $statusColumn = $tab==='tickets'?'ticket_status':'status';
    $idColumn = $tab==='tickets'?'ticket_id':'feedback_id';
    $columns = $tab==='tickets'?'ticket_id,subject,priority,ticket_status AS status,created_at,updated_at':'feedback_id,category,message,status,created_at';
    $values = [$owner];
    if ($state!=='all') { $source.=' AND '.$statusColumn.'=?'; $values[]=$state; }
    $count=(int)platform_query($db,'SELECT COUNT(*) '.$source,$values)->fetchColumn();
    $pages=max(1,(int)ceil($count/10)); $page=min((int)$page,$pages);
    $rows=platform_query($db,'SELECT '.$columns.' '.$source." ORDER BY created_at DESC,$idColumn DESC LIMIT 10 OFFSET ".(($page-1)*10),$values)->fetchAll();
    return compact('tab','state','states','rows','count','pages','page');
}
function support_change(PDO $db, int $owner, string $action, int $id, array $input, string $key): array
{
    if (!$db->inTransaction()) { throw new LogicException('Support writes require a transaction.'); }
    if (!in_array($action,['new_ticket','reply','feedback'],true)) { throw new InvalidArgumentException('This action is read-only.'); }
    // Serialize retries and suspension against the actual owner account.
    platform_query($db,'SELECT user_id FROM users WHERE user_id=? FOR UPDATE',[$owner])->fetch();
    $active=platform_query($db,"SELECT u.user_id FROM users u JOIN roles r ON r.role_id=u.role_id
        WHERE u.user_id=? AND u.account_status='active' AND r.role_type='owner'",[$owner])->fetchColumn();
    if (!$active) { throw new DomainException('Your account is unavailable. Please sign in again.'); }
    if (!preg_match('/\A[a-f0-9]{64}\z/',$key)) { throw new InvalidArgumentException('Reopen this form before saving.'); }
    $payload=[$action,$id];
    if ($action==='new_ticket') {
        $subject=support_text($input,'subject',200); $description=support_text($input,'description',4000);
        $priority=sale_text($input,'priority','normal');
        if (!in_array($priority,['low','normal','high'],true)) { throw new InvalidArgumentException('Choose a valid priority.'); }
        array_push($payload,$subject,$description,$priority);
    } elseif ($action==='reply') {
        $message=support_text($input,'message',4000); $payload[]=$message;
    } else {
        $message=support_text($input,'message',4000); $category=sale_text($input,'category','suggestion');
        if (!in_array($category,['bug','suggestion','compliment','other'],true)) { throw new InvalidArgumentException('Choose a valid feedback category.'); }
        array_push($payload,$message,$category);
    }
    $hash=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR));
    $previous=platform_query($db,"SELECT entity_id,after_data FROM activity_logs WHERE actor_user_id=?
        AND action_code IN ('OWNER_TICKET_CREATED','OWNER_TICKET_REPLIED','OWNER_FEEDBACK_SUBMITTED')
        AND JSON_UNQUOTE(JSON_EXTRACT(after_data,'$.request_key'))=? FOR UPDATE",[$owner,$key])->fetch();
    if ($previous) {
        $saved=json_decode($previous['after_data'],true);
        if (!hash_equals($saved['payload_hash']??'',$hash)) { throw new InvalidArgumentException('This submission was already used. Reopen the form.'); }
        return ['id'=>(int)$previous['entity_id'],'replayed'=>true];
    }
    if ($action==='new_ticket') {
        platform_query($db,'INSERT INTO support_tickets (submitted_by,subject,description,priority) VALUES (?,?,?,?)',[$owner,$subject,$description,$priority]);
        $id=(int)$db->lastInsertId(); $code='OWNER_TICKET_CREATED'; $entity='support_ticket';
    } elseif ($action==='reply') {
        $ticket=support_ticket($db,$owner,$id,true);
        if ($ticket['ticket_status']==='closed') { throw new InvalidArgumentException('This ticket is closed. Create a new ticket for further help.'); }
        platform_query($db,'INSERT INTO ticket_messages (ticket_id,sender_user_id,message_body) VALUES (?,?,?)',[$id,$owner,$message]);
        if ($ticket['ticket_status']==='resolved') {
            platform_query($db,"INSERT INTO ticket_status_history (ticket_id,changed_by,old_status,new_status) VALUES (?,?,'resolved','open')",[$id,$owner]);
            platform_query($db,"UPDATE support_tickets SET ticket_status='open',resolved_at=NULL WHERE ticket_id=? AND submitted_by=?",[$id,$owner]);
        }
        platform_query($db,'UPDATE support_tickets SET updated_at=CURRENT_TIMESTAMP WHERE ticket_id=? AND submitted_by=?',[$id,$owner]);
        $code='OWNER_TICKET_REPLIED'; $entity='support_ticket';
    } else {
        platform_query($db,'INSERT INTO user_feedback (submitted_by,category,message) VALUES (?,?,?)',[$owner,$category,$message]);
        $id=(int)$db->lastInsertId(); $code='OWNER_FEEDBACK_SUBMITTED'; $entity='user_feedback';
    }
    // The audit stores retry metadata, never private ticket or feedback content.
    platform_query($db,'INSERT INTO activity_logs (actor_user_id,action_code,entity_type,entity_id,after_data) VALUES (?,?,?,?,?)',
        [$owner,$code,$entity,$id,json_encode(['request_key'=>$key,'payload_hash'=>$hash],JSON_THROW_ON_ERROR)]);
    return ['id'=>$id,'replayed'=>false];
}
