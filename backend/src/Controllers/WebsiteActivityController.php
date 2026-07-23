<?php

namespace App\Controllers;

use App\Support\Json;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Phase 6/8. Sits behind JwtAuthMiddleware + ConsentRequiredMiddleware (see
 * public/index.php), identical gate to ActivityController -- the browser
 * extension authenticates as the employee and physically cannot post
 * website activity until that employee has changed their password AND
 * accepted the current monitoring policy version.
 *
 * Mirrors ActivityController::ingest deliberately (same batching, same
 * timestamp-window validation, same client_batch_id dedup pattern) so the
 * two ingest paths behave identically from an admin/ops point of view --
 * the only real difference is domain vs. active_window and the domain
 * format check below.
 *
 * Privacy requirement: this endpoint only ever accepts a bare domain, never
 * a full URL. It deliberately does NOT read or store any other field a
 * client might send (path, query string, page title, form contents,
 * search queries, etc.) -- those are dropped silently by the explicit
 * whitelist below. isValidDomain() additionally rejects anything that
 * looks like it still has a scheme/path/query in it, so a buggy or
 * compromised extension build can't smuggle a full URL through even if it
 * tried -- this is enforced server-side, not just "the extension is
 * supposed to only send that."
 */
class WebsiteActivityController
{
    private PDO $db;

    /** Reject entries older than this many hours, to stop a stale/replayed
     *  extension queue from silently backfilling activity for days it
     *  shouldn't. Same value as ActivityController for consistency. */
    private const MAX_BACKDATE_HOURS = 48;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * POST /api/activity/website
     * Body: { "entries": [ { "timestamp": "2026-07-16 10:15:00",
     *                          "domain": "github.com",
     *                          "is_idle": false,
     *                          "duration_seconds": 60 }, ... ] }
     * Batched so the browser extension can queue locally (chrome.storage)
     * and flush periodically instead of needing one request per tick.
     */
    public function ingest(Request $request, Response $response): Response
    {
        $auth = $request->getAttribute('auth');
        $employeeId = (int) $auth['sub'];

        $body = Json::body($request);
        $entries = $body['entries'] ?? null;

        if (!is_array($entries) || count($entries) === 0) {
            return Json::write($response, ['error' => 'entries must be a non-empty array'], 422);
        }
        if (count($entries) > 500) {
            return Json::write($response, ['error' => 'entries exceeds max batch size of 500'], 422);
        }

        $cutoff = new \DateTimeImmutable('-' . self::MAX_BACKDATE_HOURS . ' hours');
        $rows = [];
        foreach ($entries as $i => $entry) {
            if (!is_array($entry)) {
                return Json::write($response, ['error' => "entries[$i] must be an object"], 422);
            }

            // Explicit whitelist -- any other key present is simply ignored,
            // never read, never stored (see class docblock).
            $timestampRaw = (string) ($entry['timestamp'] ?? '');
            $domain = $entry['domain'] ?? null;
            $isIdle = (bool) ($entry['is_idle'] ?? false);
            $durationSeconds = $entry['duration_seconds'] ?? null;
            $clientBatchId = $entry['client_batch_id'] ?? null;
            if ($clientBatchId !== null && (!is_string($clientBatchId) || strlen($clientBatchId) > 36)) {
                return Json::write($response, ['error' => "entries[$i].client_batch_id must be a string up to 36 chars"], 422);
            }

            $timestamp = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $timestampRaw)
                ?: \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $timestampRaw);
            if (!$timestamp) {
                return Json::write($response, ['error' => "entries[$i].timestamp is not a valid date"], 422);
            }
            if ($timestamp < $cutoff || $timestamp > new \DateTimeImmutable('+5 minutes')) {
                return Json::write($response, ['error' => "entries[$i].timestamp is out of the acceptable window"], 422);
            }
            if ($domain !== null) {
                if (!is_string($domain) || !self::isValidDomain($domain)) {
                    return Json::write($response, ['error' => "entries[$i].domain must be a bare hostname (no scheme, path, or query string)"], 422);
                }
            }
            if (!is_int($durationSeconds) || $durationSeconds < 0 || $durationSeconds > 3600) {
                return Json::write($response, ['error' => "entries[$i].duration_seconds must be an integer between 0 and 3600"], 422);
            }

            $rows[] = [
                $employeeId,
                $timestamp->format('Y-m-d H:i:s'),
                $domain,
                $isIdle ? 1 : 0,
                $durationSeconds,
                $clientBatchId,
            ];
        }

        $insert = $this->db->prepare(
            'INSERT INTO website_usage_logs (employee_id, timestamp, domain, is_idle, duration_seconds, client_batch_id)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = id'
            // Deliberate no-op on a retried (employee_id, client_batch_id)
            // pair -- see migration 017's docblock and ActivityController's
            // identical pattern.
        );
        $this->db->beginTransaction();
        try {
            foreach ($rows as $row) {
                $insert->execute($row);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return Json::write($response, ['error' => 'Failed to store website activity entries.'], 500);
        }

        return Json::write($response, ['message' => 'Website activity recorded.', 'entries_stored' => count($rows)], 201);
    }

    /**
     * Bare-hostname check: letters/digits/hyphens in dot-separated labels
     * only (also matches "localhost" and bare IPv4). Rejects anything
     * containing "/", "?", "#", "@", or whitespace outright, which is what
     * would show up if a client accidentally sent a full URL instead of
     * just the hostname -- that's the actual privacy boundary this
     * function exists to enforce, not RFC-perfect hostname validation.
     */
    public static function isValidDomain(string $domain): bool
    {
        if ($domain === '' || strlen($domain) > 255) {
            return false;
        }
        if (preg_match('/[\/\?#@\s]/', $domain)) {
            return false;
        }
        return (bool) preg_match(
            '/^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/',
            $domain
        );
    }
}
