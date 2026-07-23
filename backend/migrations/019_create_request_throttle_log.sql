-- Migration 019: request_throttle_log
-- Phase 14. Backs App\Support\RateLimiter -- a small sliding-window counter
-- for the two public, unauthenticated endpoints an attacker could otherwise
-- hammer for free: POST /api/login (credential stuffing / brute force) and
-- POST /api/register/send-otp (OTP/email spam, and a cheap way to probe
-- which emails are already registered). Deliberately one generic table
-- instead of a bucket-specific one per endpoint, since the access pattern
-- (insert a hit, count recent hits for an identifier) is identical for both
-- and for any future throttled endpoint.
--
-- Only FAILED attempts are recorded (see AuthController::login), so a
-- legitimate user logging in correctly and repeatedly (multiple devices,
-- token refresh, etc.) never gets throttled by their own normal use.
CREATE TABLE IF NOT EXISTS request_throttle_log (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bucket      VARCHAR(60)     NOT NULL,   -- e.g. 'login', 'send_otp'
    identifier  VARCHAR(190)    NOT NULL,   -- e.g. "username|ip" or "email"
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_throttle_bucket_identifier_time (bucket, identifier, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
