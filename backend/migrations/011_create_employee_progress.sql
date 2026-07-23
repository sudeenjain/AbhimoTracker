-- Migration 011: employee_progress
-- One row per work session, updated in place (not a log) -- holds the
-- employee's latest self-reported task/progress. Optional: an employee can
-- sign in and never touch this, admin just sees "no update yet".

CREATE TABLE IF NOT EXISTS employee_progress (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id         INT UNSIGNED        NOT NULL,
    work_session_id     BIGINT UNSIGNED     NOT NULL,
    task_description    VARCHAR(255)        NULL,
    progress_status     ENUM('not_started','in_progress','blocked','completed') NOT NULL DEFAULT 'not_started',
    progress_percent    TINYINT UNSIGNED    NULL,
    remarks             VARCHAR(500)        NULL,
    updated_at          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_progress_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    CONSTRAINT fk_progress_work_session FOREIGN KEY (work_session_id) REFERENCES employee_work_sessions(id) ON DELETE CASCADE,
    UNIQUE KEY uq_progress_work_session (work_session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
