-- Migration 004: attendance_logs
-- One row per employee per calendar day. sign-in creates the row,
-- sign-out updates the same row (see AttendanceController).

CREATE TABLE IF NOT EXISTS attendance_logs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id     INT UNSIGNED        NOT NULL,
    sign_in_time    DATETIME            NULL,
    sign_out_time   DATETIME            NULL,
    date            DATE                NOT NULL,
    CONSTRAINT fk_attendance_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    UNIQUE KEY uq_attendance_employee_date (employee_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
