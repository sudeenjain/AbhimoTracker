-- Migration 001: employees
-- New registrations always start as 'pending' with no username/password until admin approval (Phase 1).

CREATE TABLE IF NOT EXISTS employees (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150)        NOT NULL,
    email           VARCHAR(190)        NOT NULL,
    phone           VARCHAR(30)         NOT NULL,
    role            VARCHAR(100)        NOT NULL,
    status          ENUM('pending','active','rejected') NOT NULL DEFAULT 'pending',
    username        VARCHAR(60)         NULL,
    password_hash   VARCHAR(255)        NULL,
    must_change_password TINYINT(1)     NOT NULL DEFAULT 1,
    created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_employees_email (email),
    UNIQUE KEY uq_employees_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
