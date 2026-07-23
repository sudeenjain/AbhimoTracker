-- Migration 010: employee_work_sessions
-- Browser-based real-time work session tracking (separate from the
-- desktop-agent-oriented activity_logs/daily_summary tables added in
-- migrations 005/006, which are left untouched).
--
-- One row per attendance_logs row (see UNIQUE KEY below) -- created when an
-- employee signs in, closed when they sign out. Populated by periodic
-- heartbeats from the browser tab (mouse/keyboard/scroll/touch/visibility
-- signals only -- see ActivityLogs vs WorkSessionController docblocks for
-- what is and is not recorded).

CREATE TABLE IF NOT EXISTS employee_work_sessions (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id         INT UNSIGNED        NOT NULL,
    attendance_id       INT UNSIGNED        NOT NULL,
    session_date        DATE                NOT NULL,
    sign_in_time        DATETIME            NOT NULL,
    sign_out_time       DATETIME            NULL,
    last_heartbeat_at   DATETIME            NULL,
    last_activity_at    DATETIME            NULL,
    current_status      ENUM('ACTIVE','IDLE','TAB_HIDDEN','OFFLINE','SIGNED_OUT') NOT NULL DEFAULT 'ACTIVE',
    active_seconds      INT UNSIGNED        NOT NULL DEFAULT 0,
    idle_seconds         INT UNSIGNED        NOT NULL DEFAULT 0,
    created_at          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_work_session_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    CONSTRAINT fk_work_session_attendance FOREIGN KEY (attendance_id) REFERENCES attendance_logs(id) ON DELETE CASCADE,
    UNIQUE KEY uq_work_session_attendance (attendance_id),
    KEY idx_work_session_employee_date (employee_id, session_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
