-- Migration 012: add 'awaiting_activation' to employees.status
-- New lifecycle: pending -> (admin approves) -> awaiting_activation
-- -> (employee downloads tracker + accepts terms) -> active
-- An admin-approved employee has no credentials yet at this point -- they
-- get issued only after the employee accepts the monitoring policy on the
-- onboarding landing page (see OnboardingController). Previously "approve"
-- jumped straight to 'active' with credentials issued immediately.
ALTER TABLE employees
MODIFY status ENUM(
        'pending',
        'awaiting_activation',
        'active',
        'rejected'
    ) NOT NULL DEFAULT 'pending';