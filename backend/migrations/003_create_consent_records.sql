-- Migration 003: consent_records
-- One row per employee per policy version accepted. Recording (employee_id,
-- policy_version, accepted_at, ip_address) is what legally "switches on"
-- tracking for that employee -- see ConsentMiddleware.

CREATE TABLE IF NOT EXISTS consent_records (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id     INT UNSIGNED        NOT NULL,
    policy_version  INT UNSIGNED        NOT NULL,
    accepted_at     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address      VARCHAR(45)         NOT NULL,
    CONSTRAINT fk_consent_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    CONSTRAINT fk_consent_policy_version FOREIGN KEY (policy_version) REFERENCES monitoring_policy(version),
    UNIQUE KEY uq_consent_employee_version (employee_id, policy_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
