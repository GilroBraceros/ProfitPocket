<?php
declare(strict_types=1);

// Only general, explicitly supported settings may reach the owner interface.
const PLATFORM_PUBLIC_KEYS = ['platform_name','platform_description','support_email','support_phone'];
function platform_query(PDO $db, string $sql, array $values = []): PDOStatement
{
    $statement = $db->prepare($sql);
    $statement->execute($values);
    return $statement;
}
function platform_information(PDO $db): array
{
    $rows = platform_query($db, "SELECT setting_key,setting_value FROM platform_settings
        WHERE setting_key IN ('platform_name','platform_description','support_email','support_phone')")->fetchAll();
    $values = array_column($rows, 'setting_value', 'setting_key');
    if (isset($values['support_email']) && !filter_var($values['support_email'], FILTER_VALIDATE_EMAIL)) {
        unset($values['support_email']);
    }
    return $values;
}
function platform_announcements(PDO $db, int $limit = 3): array
{
    $limit = max(1, min(20, $limit));
    return platform_query($db, "SELECT announcement_id,title,body,published_at FROM announcements
        WHERE announcement_status='published' AND published_at<=CURRENT_TIMESTAMP
        ORDER BY published_at DESC,announcement_id DESC LIMIT $limit")->fetchAll();
}
function platform_announcement(PDO $db, int $id): array
{
    $row = platform_query($db, "SELECT announcement_id,title,body,published_at FROM announcements
        WHERE announcement_id=? AND announcement_status='published' AND published_at<=CURRENT_TIMESTAMP", [$id])->fetch();
    if (!$row) { throw new OutOfBoundsException('This announcement is no longer available.'); }
    return $row;
}
function platform_date(string $date): string
{
    return (new DateTimeImmutable($date))->format('j M Y, g:i a');
}
