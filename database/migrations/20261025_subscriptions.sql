-- Review before applying. Run through bin/deploy_upgrade.php after authorization.
-- No customer payment, sale, expense, view or existing security trigger is changed.
CREATE TABLE IF NOT EXISTS deployment_runs (
    run_key VARCHAR(100) NOT NULL PRIMARY KEY,
    details LONGTEXT NOT NULL CHECK (JSON_VALID(details)),
    completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscription_payment_methods (
    method_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    method_name VARCHAR(80) NOT NULL,
    recipient_name VARCHAR(150) NOT NULL,
    account_identifier VARCHAR(150) NOT NULL,
    instructions VARCHAR(2000) NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_by BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(user_id),
    CHECK (is_enabled IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS owner_subscriptions (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    started_at DATETIME DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    billing_anchor TINYINT UNSIGNED DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    KEY idx_subscription_expiry (expires_at),
    CHECK ((started_at IS NULL AND expires_at IS NULL AND billing_anchor IS NULL) OR
        (started_at IS NOT NULL AND expires_at>started_at AND billing_anchor BETWEEN 1 AND 31))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscription_payments (
    submission_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    method_id BIGINT UNSIGNED NOT NULL,
    method_snapshot LONGTEXT NOT NULL CHECK (JSON_VALID(method_snapshot)),
    amount DECIMAL(12,2) NOT NULL DEFAULT 100.00 CHECK (amount=100.00),
    reference_number VARCHAR(150) DEFAULT NULL,
    reference_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    proof_name CHAR(68) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    proof_mime VARCHAR(40) NOT NULL,
    proof_bytes INT UNSIGNED NOT NULL CHECK (proof_bytes BETWEEN 1 AND 5242880),
    proof_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by BIGINT UNSIGNED DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    review_note VARCHAR(1000) DEFAULT NULL,
    period_start DATETIME DEFAULT NULL,
    period_end DATETIME DEFAULT NULL,
    is_renewal TINYINT(1) NOT NULL DEFAULT 0,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES owner_subscriptions(user_id),
    FOREIGN KEY (method_id) REFERENCES subscription_payment_methods(method_id),
    FOREIGN KEY (reviewed_by) REFERENCES users(user_id),
    UNIQUE KEY uq_subscription_request (user_id,request_key),
    UNIQUE KEY uq_subscription_proof (user_id,proof_sha256),
    UNIQUE KEY uq_subscription_reference (reference_key),
    KEY idx_subscription_review (status,submitted_at),
    KEY idx_subscription_history (user_id,submission_id),
    CHECK ((status='pending' AND reviewed_by IS NULL AND reviewed_at IS NULL) OR
        (status IN ('approved','rejected') AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL)),
    CHECK (status<>'approved' OR (period_start IS NOT NULL AND period_end>period_start)),
    CHECK (status<>'rejected' OR CHAR_LENGTH(review_note)>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The CLI migration runner checks for this column before running the ALTER,
-- allowing safe recovery from interrupted DDL on both MySQL and MariaDB.
ALTER TABLE notification_outbox ADD COLUMN subscription_payment_id BIGINT UNSIGNED DEFAULT NULL;
