-- Migration 006: daily_summary
-- Aggregated from activity_logs + attendance_logs. This is an ACTIVITY
-- summary only -- it never contains a pay amount (see pay_decisions and
-- hard constraint #3).

CREATE TABLE IF NOT EXISTS daily_summary (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id         INT UNSIGNED    NOT NULL,
    date                DATE            NOT NULL,
    active_minutes      INT UNSIGNED    NOT NULL DEFAULT 0,
    idle_minutes        INT UNSIGNED    NOT NULL DEFAULT 0,
    punctuality_flag    VARCHAR(20)     NULL,
    CONSTRAINT fk_summary_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    UNIQUE KEY uq_summary_employee_date (employee_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
