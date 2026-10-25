<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require __DIR__.'/../includes/subscriptions.php';
try { echo json_encode(['reminder_stages_logged'=>subscription_generate_reminders(db())],JSON_THROW_ON_ERROR).PHP_EOL; }
catch (Throwable $e) { fwrite(STDERR,"Subscription reminders unavailable. Check private configuration and the approved migration.\n"); exit(1); }
