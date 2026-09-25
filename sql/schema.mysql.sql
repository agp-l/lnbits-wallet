-- Import in phpMyAdmin after creating and selecting an empty database.
-- Requires InnoDB and utf8mb4; this file contains no wallets or credentials.
CREATE TABLE IF NOT EXISTS users (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    verified_at BIGINT NULL,
    password_hash VARCHAR(255) NULL,
    wallet_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,
    wallet_name VARCHAR(255) NULL,
    invoice_key TEXT NULL,
    admin_key TEXT NULL,
    provisioning_at BIGINT NULL,
    created_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_codes (
    email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at BIGINT NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    sent_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
    `key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    window_at BIGINT NOT NULL,
    attempts INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transfers (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    sender_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    recipient_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    sats BIGINT NOT NULL,
    state VARCHAR(20) NOT NULL,
    invoice_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NULL,
    payment_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at BIGINT NOT NULL,
    updated_at BIGINT NOT NULL,
    INDEX transfers_sender (sender_id, created_at),
    CONSTRAINT transfers_sender_fk FOREIGN KEY (sender_id) REFERENCES users(id),
    CONSTRAINT transfers_recipient_fk FOREIGN KEY (recipient_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
