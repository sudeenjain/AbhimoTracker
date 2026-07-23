-- Migration 014: token_version on employees
-- Enables server-side JWT revocation. Every JWT issued at login carries the
-- employee's token_version at issue time as claim "tv". JwtAuthMiddleware
-- compares that claim against the current DB value on every request; a
-- mismatch means the token was issued before an admin revoked sessions
-- (see AdminEmployeeController::revokeSessions), so it's rejected even
-- though it hasn't expired yet. Bumping this column is the only way to kill
-- an already-issued, not-yet-expired token -- there's no separate blocklist
-- table since a single integer per employee is enough.
ALTER TABLE employees
    ADD COLUMN token_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER must_change_password;