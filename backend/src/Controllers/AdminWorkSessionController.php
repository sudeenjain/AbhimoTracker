<?php

namespace App\Controllers;

use App\Support\Json;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin-only (see AdminOnlyMiddleware in public/index.php). Read-only views
 * over employee_work_sessions / employee_progress -- polled by the frontend
 * (Phase 7: simple AJAX polling every 10-30s, not WebSockets/SSE, since that
 * covers the need without adding a second server process to this stack).
 */
class AdminWorkSessionController
{
    private PDO $db;
    private array $config;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->config = require __DIR__ . '/../../config/tracking.php';
    }

    /** GET /api/admin/work-sessions/live -- "who's active right now" */
    public function liveStatus(Request $request, Response $response): Response
    {
        $today = date('Y-m-d');

        $stmt = $this->db->prepare(
            "SELECT e.id AS employee_id, e.name, e.role,
                    ws.id AS work_session_id, ws.sign_in_time, ws.sign_out_time,
                    ws.current_status, ws.active_seconds, ws.idle_seconds,
                    ws.last_heartbeat_at, ws.last_activity_at,
                    p.task_description, p.progress_status, p.progress_percent, p.updated_at AS progress_updated_at
             FROM employees e
             LEFT JOIN employee_work_sessions ws ON ws.employee_id = e.id AND ws.session_date = ?
             LEFT JOIN employee_progress p ON p.work_session_id = ws.id
             WHERE e.status = 'active' AND e.role != 'admin'
             ORDER BY e.name ASC"
        );
        $stmt->execute([$today]);
        $rows = $stmt->fetchAll();

        $now = new \DateTimeImmutable();
        $offlineAfter = (int) $this->config['offline_after_seconds'];

        foreach ($rows as &$row) {
            if ($row['work_session_id'] === null) {
                $row['status'] = 'SIGNED_OUT'; // never signed in today
                $row['seconds_since_heartbeat'] = null;
            } elseif ($row['current_status'] === 'SIGNED_OUT') {
                $row['status'] = 'SIGNED_OUT';
                $row['seconds_since_heartbeat'] = null;
            } else {
                $lastHeartbeat = $row['last_heartbeat_at'] ? new \DateTimeImmutable($row['last_heartbeat_at']) : null;
                $secondsSince = $lastHeartbeat ? ($now->getTimestamp() - $lastHeartbeat->getTimestamp()) : null;
                $row['seconds_since_heartbeat'] = $secondsSince;
                // Stale-heartbeat override: if the browser stopped reporting
                // (crash, sleep, disconnect -- Phase 10), show OFFLINE
                // regardless of the last status the session had.
                $row['status'] = ($secondsSince === null || $secondsSince > $offlineAfter)
                    ? 'OFFLINE'
                    : $row['current_status'];
            }
            $row['total_seconds'] = (int) $row['active_seconds'] + (int) $row['idle_seconds'];
        }
        unset($row);

        return Json::write($response, ['generated_at' => $now->format('Y-m-d H:i:s'), 'employees' => $rows]);
    }

    /** GET /api/admin/work-sessions/summary?date=YYYY-MM-DD (defaults to today, Phase 8) */
    public function summary(Request $request, Response $response): Response
    {
        $date = $request->getQueryParams()['date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return Json::write($response, ['error' => 'date must be in YYYY-MM-DD format'], 422);
        }

        // Schema only allows one attendance row (and therefore one work
        // session) per employee per day, so "sessions" is always 0 or 1 here
        // -- no risk of double-counting overlapping sessions.
        $stmt = $this->db->prepare(
            "SELECT e.id AS employee_id, e.name,
                    a.sign_in_time AS first_sign_in, a.sign_out_time AS last_sign_out,
                    ws.active_seconds, ws.idle_seconds, ws.current_status,
                    p.task_description, p.progress_status, p.progress_percent
             FROM employees e
             LEFT JOIN attendance_logs a ON a.employee_id = e.id AND a.date = ?
             LEFT JOIN employee_work_sessions ws ON ws.attendance_id = a.id
             LEFT JOIN employee_progress p ON p.work_session_id = ws.id
             WHERE e.status = 'active' AND e.role != 'admin'
             ORDER BY e.name ASC"
        );
        $stmt->execute([$date]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['sessions'] = $row['first_sign_in'] ? 1 : 0;
            $row['total_logged_in_seconds'] = ((int) $row['active_seconds']) + ((int) $row['idle_seconds']);
        }
        unset($row);

        return Json::write($response, ['date' => $date, 'summary' => $rows]);
    }
}
