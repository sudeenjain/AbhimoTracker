-- Migration 008: add availability_preference to employees.
--
-- DEVIATION FROM SPEC -- flagged for review.
-- Phase 1 requires the public registration form to capture "daily
-- availability preference", but the employees table as originally specified
-- has no column for it. Adding one nullable column rather than dropping the
-- field or overloading an existing one. Revert with:
--   ALTER TABLE employees DROP COLUMN availability_preference;
-- if you'd rather handle this differently (e.g. a separate table).

ALTER TABLE employees
    ADD COLUMN availability_preference VARCHAR(255) NULL AFTER role;
