-- Existing MySQL installations: run once in phpMyAdmin before deploying the password feature.
-- Existing users and wallets remain unchanged; passwords start unset.
ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) NULL AFTER verified_at;
