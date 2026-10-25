<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(403);exit;}
require_once __DIR__.'/../includes/subscriptions.php';
// Positive legacy module tests need an active fictional subscription fixture.
// Never grant time to an actual account or create a pretend production payment.
function test_activate_subscription(PDO $db,int $user): void
{
    if (!subscription_schema($db)) {return;}
    $email=subscription_query($db,'SELECT email FROM users WHERE user_id=?',[$user])->fetchColumn();
    if (!is_string($email) || !str_ends_with($email,'@example.invalid')) {throw new RuntimeException('Paid test fixtures require a reserved fake email address.');}
    $now=new DateTimeImmutable('now',new DateTimeZone('Asia/Manila'));$anchor=(int)$now->format('j');
    subscription_query($db,'INSERT INTO owner_subscriptions (user_id,started_at,expires_at,billing_anchor) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE started_at=VALUES(started_at),expires_at=VALUES(expires_at),billing_anchor=VALUES(billing_anchor)',[$user,$now->format('Y-m-d H:i:s'),subscription_month_end($now,$anchor)->format('Y-m-d H:i:s'),$anchor]);
}
