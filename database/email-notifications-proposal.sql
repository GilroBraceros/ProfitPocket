-- Proposal: apply once only after approval. Never auto-run on page loads.
-- No existing financial table, view, trigger, or record is changed.
CREATE TABLE customer_email_preferences (
    customer_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    business_id BIGINT UNSIGNED NOT NULL,
    email_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    payment_receipts TINYINT(1) NOT NULL DEFAULT 0,
    reminders TINYINT(1) NOT NULL DEFAULT 0,
    consent_note VARCHAR(255) DEFAULT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id,business_id) REFERENCES customers(customer_id,business_id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(user_id),
    CHECK (payment_receipts IN (0,1) AND reminders IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_outbox (
    notification_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    notification_type VARCHAR(40) NOT NULL,
    event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id BIGINT UNSIGNED DEFAULT NULL,
    business_id BIGINT UNSIGNED DEFAULT NULL,
    customer_id BIGINT UNSIGNED DEFAULT NULL,
    sale_id BIGINT UNSIGNED DEFAULT NULL,
    payment_id BIGINT UNSIGNED DEFAULT NULL,
    reset_id BIGINT UNSIGNED DEFAULT NULL,
    reset_nonce CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    recipient_email VARCHAR(191) DEFAULT NULL,
    status ENUM('pending','processing','accepted','failed','skipped','uncertain') NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    failure_code VARCHAR(40) DEFAULT NULL,
    message_id VARCHAR(255) DEFAULT NULL,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    first_attempt_at DATETIME DEFAULT NULL,
    attempted_at DATETIME DEFAULT NULL,
    accepted_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notification_event (event_key),
    KEY idx_notification_pending (status,available_at),
    KEY idx_notification_customer (business_id,customer_id),
    KEY idx_notification_reset (reset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
