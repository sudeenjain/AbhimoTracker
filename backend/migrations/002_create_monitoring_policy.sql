-- Migration 002: monitoring_policy
-- Versioned consent policy text. Employees must accept the CURRENT version
-- (highest effective_date) before any tracking data can be recorded for them.

CREATE TABLE IF NOT EXISTS monitoring_policy (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    version         INT UNSIGNED        NOT NULL,
    content         TEXT                NOT NULL,
    effective_date  DATE                NOT NULL,
    created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_monitoring_policy_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
