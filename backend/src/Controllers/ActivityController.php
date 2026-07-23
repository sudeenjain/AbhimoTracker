<?php

namespace App\Controllers;

use App\Support\Json;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Phase 4. Sits behind JwtAuthMiddleware + ConsentRequiredMiddleware (see
 * public/index.php) -- an employee's agent physically cannot post activity
 * until that employee has changed their password AND accepted the current
 * monitoring policy version.
 *
 * Hard constraint #1: this endpoint only ever accepts active-window-title +
 * idle-time + a duration. It deliberately does NOT read or store any other
 * field a client might send (screenshots, keystrokes, webcam/mic, URLs,
 * clipboard, etc.) -- those are dropped silently by the explicit whitelist
 * below, not just "not currently used."
 */
class ActivityController
{
    private PDO $db;

    /** Reject entries older than this many hours, to stop a stale/replayed
     *  agent queue from silently backfilling activity for days it shouldn't. */
    private const MAX_BACKDATE_HOURS = 48;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * POST /api/activity/ingest
     * Body: { "entries": [ { "timestamp": "2026-07-16 10:15:00",
     *                          "active_window": "VSCode - index.php",
     *                          "is_idle": false,
     *                          "duration_seconds": 60 }, ... ] }
     * Batched so the desktop agent can queue locally and flush periodically
     * (e.g. on a laptop that was offline) instead of needing one request per
     * 60-second tick.
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
            // never read, never stored. See class docblock (hard constraint #1).
            $timestampRaw = (string) ($entry['timestamp'] ?? '');
            $activeWindow = $entry['active_window'] ?? null;
            $isIdle = (bool) ($entry['is_idle'] ?? false);
            $durationSeconds = $entry['duration_seconds'] ?? null;
            // Optional -- see migration 016. Older/other clients that don't
            // send this just don't get dedup protection, same as before.
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
            if ($activeWindow !== null && (!is_string($activeWindow) || strlen($activeWindow) > 255)) {
                return Json::write($response, ['error' => "entries[$i].active_window must be a string up to 255 chars"], 422);
            }
            if (!is_int($durationSeconds) || $durationSeconds < 0 || $durationSeconds > 3600) {
                return Json::write($response, ['error' => "entries[$i].duration_seconds must be an integer between 0 and 3600"], 422);
            }

            $rows[] = [
                $employeeId,
                $timestamp->format('Y-m-d H:i:s'),
                $activeWindow,
                $isIdle ? 1 : 0,
                $durationSeconds,
                $clientBatchId,
            ];
        }

        $insert = $this->db->prepare(
            'INSERT INTO activity_logs (employee_id, timestamp, active_window, is_idle, duration_seconds, client_batch_id)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = id'
            // ON DUPLICATE KEY UPDATE id = id is a deliberate no-op: it
            // makes a retried (employee_id, client_batch_id) pair silently
            // skip re-inserting instead of erroring or creating a second
            // row, without touching any of the entry's actual data.
        );
        $this->db->beginTransaction();
        try {
            foreach ($rows as $row) {
                $insert->execute($row);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return Json::write($response, ['error' => 'Failed to store activity entries.'], 500);
        }

        return Json::write($response, ['message' => 'Activity recorded.', 'entries_stored' => count($rows)], 201);
    }
}
