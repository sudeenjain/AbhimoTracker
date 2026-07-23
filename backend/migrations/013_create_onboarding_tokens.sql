-- Migration 013: onboarding_tokens
-- One token per employee (see UNIQUE KEY), issued when an admin approves
-- them, consumed when they accept the monitoring policy on the onboarding
-- landing page. Public-facing (no login exists yet at this point), so the
-- token itself -- long, random, single-use -- is what proves "this is the
-- person that email went to", not a session/JWT.
CREATE TABLE IF NOT EXISTS onboarding_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    token CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    CONSTRAINT fk_onboarding_token_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    UNIQUE KEY uq_onboarding_token_employee (employee_id),
    UNIQUE KEY uq_onboarding_token_value (token)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;