-- Migration 018: consent withdrawal
-- Phase 13. Adds a nullable withdrawn_at to consent_records so an employee
-- can revoke consent for the currently-accepted monitoring_policy version
-- without deleting the historical acceptance row -- the original
-- (employee_id, policy_version, accepted_at, ip_address) stays intact as a
-- legal record; only withdrawn_at is set. This matches the existing
-- consent_records design (one row per employee per policy version) rather
-- than adding a parallel table.
--
-- PolicyRepository::hasConsentedToCurrent now excludes withdrawn rows, so
-- ConsentRequiredMiddleware immediately re-blocks every consent-gated route
-- (attendance, activity ingest, work-session) the next time the employee's
-- agent/browser/tray checks in -- same 403 { reason: "consent_required" }
-- shape as never having consented at all.
ALTER TABLE consent_records
    ADD COLUMN withdrawn_at DATETIME NULL AFTER accepted_at;
