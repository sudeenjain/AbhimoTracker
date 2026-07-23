-- Migration 007: pay_decisions
-- Pay/incentive amounts are NEVER computed by the system (hard constraint #3).
-- This table only records a human admin's decision: who decided, what they
-- decided, why (notes), and when. No code path in this project may INSERT
-- into this table except an authenticated admin-initiated request.

CREATE TABLE IF NOT EXISTS pay_decisions (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id         INT UNSIGNED    NOT NULL,
    period              VARCHAR(20)     NOT NULL COMMENT 'e.g. 2026-07 for a monthly period',
    amount              DECIMAL(12,2)   NOT NULL,
    decided_by_admin_id INT UNSIGNED    NOT NULL,
    notes               TEXT            NULL,
    decided_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pay_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    CONSTRAINT fk_pay_admin FOREIGN KEY (decided_by_admin_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
