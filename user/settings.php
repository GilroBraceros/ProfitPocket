<?php
require_once __DIR__ . '/../includes/auth.php';
$user = require_role('owner');
$page_title = 'Business settings';
require __DIR__ . '/../includes/module_pending.php';
