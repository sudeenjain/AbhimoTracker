-- Migration 016: client_batch_id on activity_logs
-- upload-queue.js (desktop-tray) generates a stable id per queued entry
-- specifically so a retried POST /api/activity/ingest -- after a response
-- was lost but the write actually succeeded (network blip, agent restart
-- mid-flush) -- doesn't insert the same stretch of time twice. That only
-- works if the backend can recognize "I've already stored this one," which
-- needs a place to record the id and a constraint to enforce it: this
-- column and its unique key.
--
-- NULL is intentionally allowed and NOT deduped against other NULLs -- MySQL
-- treats each NULL as distinct in a unique index, so any caller that omits
-- client_batch_id (e.g. an older agent build) just gets the old undeduped
-- behavior instead of an error.
ALTER TABLE activity_logs
    ADD COLUMN client_batch_id CHAR(36) NULL AFTER duration_seconds,
    ADD UNIQUE KEY uq_activity_employee_client_batch (employee_id, client_batch_id);