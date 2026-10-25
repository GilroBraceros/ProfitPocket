<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
date_default_timezone_set('Asia/Manila');
require __DIR__.'/../config/database.php'; require __DIR__.'/../includes/notifications.php';
try { echo json_encode(['eligible_stages_logged'=>notification_generate_reminders(db())],JSON_THROW_ON_ERROR).PHP_EOL; }
catch (Throwable $e) { fwrite(STDERR,"Reminder job unavailable. Check private configuration and approved migration.\n"); exit(1); }
