-- Additive proposal. Apply with bin/deploy_upgrade.php after specific approval.
-- The runner skips already-present columns for interrupted-DDL recovery.
ALTER TABLE subscription_payment_methods ADD COLUMN method_category ENUM('gcash','bank','other') NOT NULL DEFAULT 'other';
ALTER TABLE subscription_payment_methods ADD COLUMN bank_name VARCHAR(80) DEFAULT NULL;
ALTER TABLE subscription_payment_methods ADD COLUMN qr_filename CHAR(68) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL;

CREATE TABLE IF NOT EXISTS registration_onboarding (
    onboarding_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    initial_submission_id BIGINT UNSIGNED DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_onboarding_user (user_id),
    UNIQUE KEY uq_onboarding_token (token_hash),
    UNIQUE KEY uq_onboarding_request (request_key),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (initial_submission_id) REFERENCES subscription_payments(submission_id),
    CHECK (expires_at>created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
