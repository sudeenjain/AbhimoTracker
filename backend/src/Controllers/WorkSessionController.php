<?php

namespace App\Controllers;

use App\Support\Json;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Browser-based real-time work-session tracking. Sits behind the same gate
 * as AttendanceController (JwtAuthMiddleware + ConsentRequiredMiddleware,
 * see public/index.php) -- an employee can't post a heartbeat until they've
 * changed their password and accepted the current monitoring policy.
 *
 * Hard constraint: the heartbeat endpoint only ever accepts two booleans
 * (is_idle, is_tab_visible) plus an optional client clock timestamp. It does
 * NOT accept or store window titles, URLs, keystrokes, screenshots, or any
 * other field a client might send -- everything else is ignored, not just
 * "not currently used" (see docblock on the old ActivityController for the
 * same pattern). An employee can only ever touch their own session -- the
 * session/attendance row is looked up from the JWT's employee id, never from
 * a client-supplied id.
 */
class WorkSessionController
{
    private PDO $db;
    private array $config;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->config = require __DIR__ . '/../../config/tracking.php';
    }

    /**
     * POST /api/work-session/heartbeat
     * Body: { "is_idle": false, "is_tab_visible": true }
     * Called every ~25s (see config/tracking.php) by the leader browser tab
     * while the employee is signed in. Idempotent-ish: calling it with no
     * open session just returns 409, it never creates attendance.
     */
    public function heartbeat(Request $request, Response $response): Response
    {
        $employeeId = (int) $request->getAttribute('auth')['sub'];
        $session = $this->openSessionFor($employeeId);

        if (!$session) {
            return Json::write($response, ['error' => 'No open work session. Sign in first.'], 409);
        }

        $body = Json::body($request);
        $isIdle = (bool) ($body['is_idle'] ?? false);
        $isTabVisible = array_key_exists('is_tab_visible', $body) ? (bool) $body['is_tab_visible'] : true;

        $now = new \DateTimeImmutable();
        $lastHeartbeat = $session['last_heartbeat_at'] ? new \DateTimeImmutable($session['last_heartbeat_at']) : new \DateTimeImmutable($session['sign_in_time']);
        $gapSeconds = max(0, $now->getTimestamp() - $lastHeartbeat->getTimestamp());
        $gapSeconds = min($gapSeconds, (int) $this->config['max_heartbeat_gap_seconds']);

        $status = !$isTabVisible ? 'TAB_HIDDEN' : ($isIdle ? 'IDLE' : 'ACTIVE');
        $countsAsActive = ($status === 'ACTIVE');

        $activeDelta = $countsAsActive ? $gapSeconds : 0;
        $idleDelta = $countsAsActive ? 0 : $gapSeconds;

        $update = $this->db->prepare(
            'UPDATE employee_work_sessions
             SET last_heartbeat_at = ?, last_activity_at = IF(? = 1, ?, last_activity_at),
                 current_status = ?, active_seconds = active_seconds + ?, idle_seconds = idle_seconds + ?
             WHERE id = ?'
        );
        $update->execute([
            $now->format('Y-m-d H:i:s'),
            $countsAsActive ? 1 : 0,
            $now->format('Y-m-d H:i:s'),
            $status,
            $activeDelta,
            $idleDelta,
            $session['id'],
        ]);

        return Json::write($response, [
            'status' => $status,
            'active_seconds' => (int) $session['active_seconds'] + $activeDelta,
            'idle_seconds' => (int) $session['idle_seconds'] + $idleDelta,
            'server_time' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * GET /api/work-session/today
     * Powers the employee's own "You are currently signed in" status panel.
     */
    public function today(Request $request, Response $response): Response
    {
        $employeeId = (int) $request->getAttribute('auth')['sub'];
        $session = $this->openSessionFor($employeeId, includeClosed: true);

        if (!$session) {
            return Json::write($response, ['session' => null, 'progress' => null]);
        }

        $progressStmt = $this->db->prepare(
            'SELECT task_description, progress_status, progress_percent, remarks, updated_at
             FROM employee_progress WHERE work_session_id = ?'
        );
        $progressStmt->execute([$session['id']]);
        $progress = $progressStmt->fetch() ?: null;

        return Json::write($response, [
            'session' => [
                'status' => $session['current_status'],
                'sign_in_time' => $session['sign_in_time'],
                'sign_out_time' => $session['sign_out_time'],
                'active_seconds' => (int) $session['active_seconds'],
                'idle_seconds' => (int) $session['idle_seconds'],
                'last_heartbeat_at' => $session['last_heartbeat_at'],
            ],
            'progress' => $progress,
        ]);
    }

    /**
     * POST /api/work-session/progress
     * Body: { "task_description": "...", "progress_status": "in_progress",
     *          "progress_percent": 70, "remarks": "..." }
     * All fields optional (Phase 6: progress updates are opt-in). Upserts
     * a single row per work session -- this is current state, not a log.
     */
    public function updateProgress(Request $request, Response $response): Response
    {
        $employeeId = (int) $request->getAttribute('auth')['sub'];
        $session = $this->openSessionFor($employeeId);

        if (!$session) {
            return Json::write($response, ['error' => 'No open work session. Sign in first.'], 409);
        }

        $body = Json::body($request);
        $taskDescription = isset($body['task_description']) ? trim((string) $body['task_description']) : null;
        $progressStatus = $body['progress_status'] ?? 'not_started';
        $progressPercent = array_key_exists('progress_percent', $body) ? $body['progress_percent'] : null;
        $remarks = isset($body['remarks']) ? trim((string) $body['remarks']) : null;

        $validStatuses = ['not_started', 'in_progress', 'blocked', 'completed'];
        if (!in_array($progressStatus, $validStatuses, true)) {
            return Json::write($response, ['error' => 'progress_status must be one of: ' . implode(', ', $validStatuses)], 422);
        }
        if ($taskDescription !== null && strlen($taskDescription) > 255) {
            return Json::write($response, ['error' => 'task_description must be 255 characters or fewer'], 422);
        }
        if ($progressPercent !== null && (!is_int($progressPercent) || $progressPercent < 0 || $progressPercent > 100)) {
            return Json::write($response, ['error' => 'progress_percent must be an integer between 0 and 100'], 422);
        }
        if ($remarks !== null && strlen($remarks) > 500) {
            return Json::write($response, ['error' => 'remarks must be 500 characters or fewer'], 422);
        }

        $upsert = $this->db->prepare(
            'INSERT INTO employee_progress (employee_id, work_session_id, task_description, progress_status, progress_percent, remarks)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                task_description = VALUES(task_description),
                progress_status = VALUES(progress_status),
                progress_percent = VALUES(progress_percent),
                remarks = VALUES(remarks)'
        );
        $upsert->execute([$employeeId, $session['id'], $taskDescription, $progressStatus, $progressPercent, $remarks]);

        return Json::write($response, ['message' => 'Progress updated.']);
    }

    /**
     * Looks up the caller's own open (not signed-out) work session for
     * today. `includeClosed` also returns today's session after sign-out,
     * for the employee's own status panel to show a final summary.
     * Always scoped to $employeeId from the JWT -- never a client-supplied id.
     */
    private function openSessionFor(int $employeeId, bool $includeClosed = false): ?array
    {
        $sql = 'SELECT * FROM employee_work_sessions WHERE employee_id = ? AND session_date = ?';
        if (!$includeClosed) {
            $sql .= ' AND sign_out_time IS NULL';
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$employeeId, date('Y-m-d')]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
