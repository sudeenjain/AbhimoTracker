-- Migration 017: website_usage_logs
-- Phase 6/7. Mirrors activity_logs (migration 005 + 016) exactly, but for
-- the browser extension's domain-only tracking instead of the desktop
-- tray's app/process tracking. Written only by WebsiteActivityController,
-- and only for employees with a recorded consent for the current
-- monitoring_policy version (same ConsentRequiredMiddleware gate as
-- /api/activity/ingest -- see public/index.php).
--
-- domain is a bare hostname (e.g. "github.com"), never a full URL --
-- enforced server-side in WebsiteActivityController::isValidDomain(), not
-- just "the extension is supposed to only send that." No path, query
-- string, or page title is ever accepted, matching hard constraint on
-- website tracking: prefer storing only the domain, never full URL paths,
-- form contents, or search queries.
--
-- client_batch_id/dedup key is included from the start here (unlike
-- activity_logs, which got it later in migration 016) since the browser
-- extension's upload queue (background.js) generates one at enqueue time
-- for every entry, same pattern as desktop-tray/upload-queue.js.

CREATE TABLE IF NOT EXISTS website_usage_logs (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id         INT UNSIGNED        NOT NULL,
    timestamp           DATETIME            NOT NULL,
    domain              VARCHAR(255)        NULL,
    is_idle             TINYINT(1)          NOT NULL DEFAULT 0,
    duration_seconds    INT UNSIGNED        NOT NULL,
    client_batch_id     CHAR(36)            NULL,
    CONSTRAINT fk_website_usage_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    KEY idx_website_usage_employee_time (employee_id, timestamp),
    -- NULL is intentionally allowed and NOT deduped against other NULLs --
    -- MySQL treats each NULL as distinct in a unique index, so a caller
    -- that omits client_batch_id just gets undeduped behavior instead of
    -- an error (same reasoning as activity_logs' equivalent key).
    UNIQUE KEY uq_website_usage_employee_client_batch (employee_id, client_batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
