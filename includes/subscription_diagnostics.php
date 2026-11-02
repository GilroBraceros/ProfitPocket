<?php
declare(strict_types=1);
require_once __DIR__.'/subscriptions.php';

// Read-only checks. Never mark a migration complete or change production schema.
// Return only fixed messages: exception text can contain private SQL/server data.
function subscription_admin_diagnosis(PDO $db, ?Throwable $failure=null): array
{
    $issues=[];
    $tables=[
        'deployment_runs'=>'run_key,details,completed_at',
        'owner_subscriptions'=>'user_id,started_at,expires_at,billing_anchor,updated_at',
        'subscription_payment_methods'=>'method_id,method_name,recipient_name,account_identifier,instructions,is_enabled,updated_by,updated_at',
        'subscription_payments'=>'submission_id,user_id,method_id,method_snapshot,amount,reference_number,reference_key,proof_name,proof_mime,proof_bytes,proof_sha256,request_key,status,reviewed_by,reviewed_at,review_note,period_start,period_end,is_renewal,submitted_at',
    ];
    $available=[];
    foreach ($tables as $table=>$columns) {
        try {
            subscription_query($db,'SELECT '.$columns.' FROM '.$table.' WHERE 1=0');
            $available[$table]=true;
        } catch (Throwable $e) {
            $number=$e instanceof PDOException?(int)($e->errorInfo[1]??0):0;
            $issues[]=match ($number) {
                1146=>['code'=>'missing_table','message'=>'Required table is missing: '.$table.'. The hosted subscription migration is incomplete.'],
                1054=>['code'=>'missing_columns','message'=>'Required columns are missing from '.$table.'. The hosted schema does not match this release.'],
                1142,1143,1044,1045=>['code'=>'database_permissions','message'=>'The hosted database account cannot read '.$table.'. The hosting administrator must check its database permissions.'],
                default=>['code'=>'database_check_failed','message'=>'Could not verify '.$table.'. Check the hosted database connection and permissions.'],
            };
        }
    }
    if (isset($available['deployment_runs'])) {
        try {
            if (!subscription_schema($db)) {
                $issues[]=['code'=>'migration_not_recorded','message'=>'Subscription migration completion is not recorded. Use the approved deployment runner; importing SQL files alone does not record completion.'];
            }
        } catch (Throwable $e) {
            $issues[]=['code'=>'migration_check_failed','message'=>'Subscription migration completion could not be verified.'];
        }
    }
    if (!function_exists('mb_strlen') || !function_exists('mb_check_encoding')) {
        $issues[]=['code'=>'missing_mbstring','message'=>'The hosted PHP mbstring extension is unavailable. Enable mbstring in Hostinger PHP configuration.'];
    }
    try {
        upgrade_config();
    } catch (Throwable $e) {
        $issues[]=['code'=>'private_config_invalid','message'=>'The private upgrade configuration cannot be loaded. It must return a PHP array and be stored outside the public web directory.'];
    }
    if (!$issues && $failure) {
        $number=$failure instanceof PDOException?(int)($failure->errorInfo[1]??0):0;
        $issues[]=match ($number) {
            1054,1146=>['code'=>'related_schema_mismatch','message'=>'A related account or business table does not match this release. Compare the hosted schema with the reviewed project schema.'],
            1267,1271=>['code'=>'database_collation','message'=>'The hosted database query failed because text collations are incompatible. The hosting administrator must review column collations.'],
            1142,1143,1044,1045=>['code'=>'database_permissions','message'=>'The hosted database account does not have permission for this operation.'],
            default=>['code'=>'operation_failed','message'=>'The subscription tables and migration record are present. The operation still failed; check PHP requirements and private payment-proof storage permissions in the hosting configuration.'],
        };
    }
    return $issues;
}
