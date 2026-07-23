-- Migration 005: activity_logs
-- Written only by the Phase 4 tracking-agent ingest endpoint, and only for
-- employees with a recorded consent for the current monitoring_policy version.
-- Captures active-window-title + idle-time only. No screenshots, no keystrokes,
-- no webcam/mic data ever get a column here (see hard constraint #1).

CREATE TABLE IF NOT EXISTS activity_logs (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id     INT UNSIGNED        NOT NULL,
    timestamp       DATETIME            NOT NULL,
    active_window   VARCHAR(255)        NULL,
    is_idle         TINYINT(1)          NOT NULL DEFAULT 0,
    duration_seconds INT UNSIGNED       NOT NULL,
    CONSTRAINT fk_activity_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    KEY idx_activity_employee_time (employee_id, timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
