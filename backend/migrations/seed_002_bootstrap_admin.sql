-- Seed: bootstrap admin account.
-- Registration always starts employees as 'pending' (hard constraint #4),
-- so the very first admin has to be created out-of-band or there would be
-- nobody able to approve anyone. This seed is that one exception.
--
-- Username: admin
-- Temp password: ChangeMe123!  (must_change_password = 1, forces reset on first login)
--
-- DELETE OR ROTATE THIS BEFORE ANY REAL DEPLOYMENT.

INSERT INTO employees (name, email, phone, role, status, username, password_hash, must_change_password, created_at)
SELECT 'System Admin', 'admin@example.com', '0000000000', 'admin', 'active', 'admin',
       '$2y$12$iSQbQY2r8U0nhtvYkrnCOeYFSOLB3v6JRUWHPMjaCEYNHsDhjhaX2', 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM employees WHERE username = 'admin');
