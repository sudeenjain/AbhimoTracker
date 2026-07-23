-- Migration 015: tray_pairing_tokens
-- Bridges the browser-based onboarding flow to the desktop tray app without
-- ever putting the plaintext temp password into a URL. abhimo-tracker.html
-- launches abhimo://pair?token=...&api=... right after activation; the tray
-- app's main process (not the browser) makes the actual exchange call
-- (see TrayPairingController::exchange), so the password only ever crosses
-- the wire as a normal HTTPS JSON response body to that native process --
-- never as a command-line arg to the OS's protocol handler, which is
-- visible in places like shell/process history on some platforms.
--
-- One row per activation (not unique-per-employee like onboarding_tokens):
-- if pairing fails the first time (tray app not installed yet, protocol
-- handler not registered), the employee can trigger it again later without
-- another admin-approval round trip.
CREATE TABLE IF NOT EXISTS tray_pairing_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    token CHAR(64) NOT NULL,
    -- Plaintext temp password, held only long enough for the tray app to
    -- collect it once. Wiped to NULL the moment exchange() consumes the
    -- token (see TrayPairingController), on top of the 5-minute expiry --
    -- this table is not a place where a live password sits for any length
    -- of time.
    password_plaintext VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    CONSTRAINT fk_tray_pairing_token_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    UNIQUE KEY uq_tray_pairing_token_value (token),
    KEY idx_tray_pairing_token_employee (employee_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;