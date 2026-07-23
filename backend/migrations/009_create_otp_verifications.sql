-- Migration 009: otp_verifications
-- Table to manage temporary OTP values during the multi-step registration flow.
CREATE TABLE IF NOT EXISTS otp_verifications (
    email VARCHAR(190) PRIMARY KEY,
    otp VARCHAR(10) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;