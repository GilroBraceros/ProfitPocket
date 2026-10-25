<?php
// Blank model only. Copy OUTSIDE public_html; configure real values privately.
return [
    // Production identity must match the installed directory and connected DB.
    'PP_ENVIRONMENT'=>'development', // production on Hostinger only
    'PP_PRODUCTION_APP_ROOT'=>'', // absolute real application directory
    'PP_PRODUCTION_DB_NAME'=>'', // exact existing ProfitPocket database name
    'PP_PRODUCTION_DB_USER'=>'', // exact existing MySQL account name, without @host
    'PP_PRODUCTION_BASE_URL'=>'https://profitpocket.online/profitpocket',
    'PP_PROVISION_TEST_ADMINS'=>false, // authorize once in the private file only
    'PP_PROVISION_TOKEN'=>'', // independent random 64-character lowercase hex secret
    'PP_ACTIVATE_EMAIL'=>false, // setup job verifies sender before enabling
    'PP_SUBSCRIPTIONS_ENABLED'=>false, // enable only after approved migration/cutover
    'PP_SUBSCRIPTION_STORAGE'=>'', // absolute private directory outside all web roots
    'PP_DEPLOY_MIGRATIONS_APPROVED'=>'', // 20261025_subscriptions after review
    'PP_EXISTING_OWNER_POLICY'=>'', // pending-payment after announcing the cutover
    'PP_BOOTSTRAP_AUTHORIZED'=>false,
    'PP_BOOTSTRAP_TOKEN'=>'', // independent 64-character random hex authorization
    'PP_BOOTSTRAP_ADMIN1_EMAIL'=>'',
    'PP_BOOTSTRAP_ADMIN1_PASSWORD'=>'', // unique strong initial password, 16–72 bytes
    'PP_BOOTSTRAP_ADMIN1_NAME'=>'',
    'PP_BOOTSTRAP_ADMIN2_EMAIL'=>'',
    'PP_BOOTSTRAP_ADMIN2_PASSWORD'=>'',
    'PP_BOOTSTRAP_ADMIN2_NAME'=>'',
    'PP_EMAIL_TEST_AUTHORIZED'=>false,
    'PP_EMAIL_TEST_ADDRESS'=>'', // your administrator-controlled inbox
];
