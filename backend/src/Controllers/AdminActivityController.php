<?php

namespace App\Controllers;

use App\Support\Json;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Phase 4, admin-only. NOTE: `liveStatus` is a polling endpoint, not a
 * push/real-time layer. A true push layer (Socket.IO + Redis pub/sub) is a
 * separate Node.js service outside this PHP app's runtime -- see README.
 * This gives the admin "who's active right now" via short-interval polling
 * from the frontend, which covers the same practical need without adding a
 * second server/process to the stack.
 */
class AdminActivityController
{
    private PDO $db;

    /** An employee is considered "online" if their most recent activity_logs
     *  row (non-idle) falls within this many seconds of now. */
    private const ONLINE_WINDOW_SECONDS = 180;

    /** How long continuously idle before this counts as "not working" for
     *  the admin panel's alert, rather than just a normal short break. */
    private const PROLONGED_IDLE_SECONDS = 3600;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** GET /api/admin/live-status */
    public function liveStatus(Request $request, Response $response): Response
    {
        // Tie-broken by row id (insertion order), not timestamp alone -- a
        // single ingest batch commonly contains several entries stamped
        // with the same second, which previously made this join fan out
        // into one duplicate employee row per tied entry.
        $stmt = $this->db->query(
            "SELECT e.id AS employee_id, e.name, e.role,
                    la.timestamp AS last_seen, la.active_window AS last_window, la.is_idle AS last_is_idle
             FROM employees e
             LEFT JOIN (
                 SELECT al1.employee_id, al1.timestamp, al1.active_window, al1.is_idle
                 FROM activity_logs al1
                 INNER JOIN (
                     SELECT employee_id, MAX(id) AS max_id
                     FROM activity_logs
                     GROUP BY employee_id
                 ) al2 ON al2.employee_id = al1.employee_id AND al2.max_id = al1.id
             ) la ON la.employee_id = e.id
             WHERE e.status = 'active' AND e.role != 'admin'
             ORDER BY e.name ASC"
        );
        $rows = $stmt->fetchAll();

        // Separate query for "when did each currently-idle employee last
        // show real activity" -- needed to tell a 2-minute idle apart from a
        // 2-hour one, which the single latest-row join above can't do on
        // its own (it only ever sees the single most recent row).
        $lastActiveStmt = $this->db->prepare(
            'SELECT MAX(timestamp) FROM activity_logs WHERE employee_id = ? AND is_idle = 0'
        );
        // Fallback for an employee who has been idle for their entire
        // recorded history (no is_idle=0 row exists at all) -- "how long
        // idle" then means "since we first started observing them today",
        // not "since their most recent idle tick" (which would always be
        // ~one tick interval and never trigger the prolonged-idle alert).
        $earliestSeenStmt = $this->db->prepare(
            'SELECT MIN(timestamp) FROM activity_logs WHERE employee_id = ?'
        );

        $now = new \DateTimeImmutable();
        foreach ($rows as &$row) {
            $lastSeen = $row['last_seen'] ? new \DateTimeImmutable($row['last_seen']) : null;
            $secondsAgo = $lastSeen ? ($now->getTimestamp() - $lastSeen->getTimestamp()) : null;
            $row['seconds_since_last_seen'] = $secondsAgo;
            $row['status'] = (!$lastSeen || $secondsAgo > self::ONLINE_WINDOW_SECONDS)
                ? 'offline'
                : (((int) $row['last_is_idle'] === 1) ? 'idle' : 'active');
            $row['last_is_idle'] = $row['last_is_idle'] === null ? null : (bool) $row['last_is_idle'];

            $row['continuous_idle_seconds'] = null;
            $row['prolonged_idle'] = false;
            if ($row['status'] === 'idle') {
                $lastActiveStmt->execute([$row['employee_id']]);
                $lastActive = $lastActiveStmt->fetchColumn();

                if ($lastActive) {
                    $since = new \DateTimeImmutable($lastActive);
                } else {
                    $earliestSeenStmt->execute([$row['employee_id']]);
                    $earliestSeen = $earliestSeenStmt->fetchColumn();
                    $since = $earliestSeen ? new \DateTimeImmutable($earliestSeen) : $now;
                }

                $continuousIdle = $now->getTimestamp() - $since->getTimestamp();
                $row['continuous_idle_seconds'] = $continuousIdle;
                $row['prolonged_idle'] = $continuousIdle >= self::PROLONGED_IDLE_SECONDS;
            }
        }
        unset($row);

        return Json::write($response, ['generated_at' => $now->format('Y-m-d H:i:s'), 'employees' => $rows]);
    }

    /**
     * GET /api/admin/activity/app-usage?date=YYYY-MM-DD (defaults to today)
     * "Which app did they use most today" -- sums duration_seconds per
     * employee per app name, active (non-idle) time only, top apps first.
     * active_window here is just the app/process name (e.g. "chrome",
     * "Code"), never a window title -- see main.js's getForegroundAppName.
     */
    public function appUsage(Request $request, Response $response): Response
    {
        $date = $request->getQueryParams()['date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return Json::write($response, ['error' => 'date must be in YYYY-MM-DD format'], 422);
        }

        $stmt = $this->db->prepare(
            "SELECT employee_id, active_window, SUM(duration_seconds) AS total_seconds
             FROM activity_logs
             WHERE DATE(timestamp) = ? AND is_idle = 0 AND active_window IS NOT NULL
             GROUP BY employee_id, active_window
             ORDER BY employee_id, total_seconds DESC"
        );
        $stmt->execute([$date]);
        $rows = $stmt->fetchAll();

        // Group into { employee_id: [ {app, total_seconds}, ... ] } so the
        // frontend doesn't have to re-bucket a flat row list itself.
        $byEmployee = [];
        foreach ($rows as $row) {
            $byEmployee[$row['employee_id']][] = [
                'app' => $row['active_window'],
                'total_seconds' => (int) $row['total_seconds'],
            ];
        }

        $namesStmt = $this->db->query("SELECT id, name FROM employees WHERE status = 'active' AND role != 'admin' ORDER BY name ASC");
        $result = [];
        foreach ($namesStmt->fetchAll() as $employee) {
            $result[] = [
                'employee_id' => (int) $employee['id'],
                'name' => $employee['name'],
                'apps' => $byEmployee[$employee['id']] ?? [],
            ];
        }

        return Json::write($response, ['date' => $date, 'employees' => $result]);
    }

    /**
     * GET /api/admin/activity/website-usage?date=YYYY-MM-DD (defaults to today)
     * Phase 6/8 counterpart to appUsage() above -- same shape, same
     * "active (non-idle) time only, top domains first" semantics, just
     * sourced from website_usage_logs (browser extension) instead of
     * activity_logs (desktop tray). domain here is always a bare hostname
     * -- see WebsiteActivityController::isValidDomain, which is what
     * guarantees that server-side, not just client convention.
     */
    public function websiteUsage(Request $request, Response $response): Response
    {
        $date = $request->getQueryParams()['date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return Json::write($response, ['error' => 'date must be in YYYY-MM-DD format'], 422);
        }

        $stmt = $this->db->prepare(
            "SELECT employee_id, domain, SUM(duration_seconds) AS total_seconds
             FROM website_usage_logs
             WHERE DATE(timestamp) = ? AND is_idle = 0 AND domain IS NOT NULL
             GROUP BY employee_id, domain
             ORDER BY employee_id, total_seconds DESC"
        );
        $stmt->execute([$date]);
        $rows = $stmt->fetchAll();

        $byEmployee = [];
        foreach ($rows as $row) {
            $byEmployee[$row['employee_id']][] = [
                'domain' => $row['domain'],
                'total_seconds' => (int) $row['total_seconds'],
            ];
        }

        $namesStmt = $this->db->query("SELECT id, name FROM employees WHERE status = 'active' AND role != 'admin' ORDER BY name ASC");
        $result = [];
        foreach ($namesStmt->fetchAll() as $employee) {
            $result[] = [
                'employee_id' => (int) $employee['id'],
                'name' => $employee['name'],
                'websites' => $byEmployee[$employee['id']] ?? [],
            ];
        }

        return Json::write($response, ['date' => $date, 'employees' => $result]);
    }

    /** GET /api/admin/daily-summary?date=YYYY-MM-DD (defaults to today) */
    public function forDate(Request $request, Response $response): Response
    {
        $date = $request->getQueryParams()['date'] ?? date('Y-m-d');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return Json::write($response, ['error' => 'date must be in YYYY-MM-DD format'], 422);
        }

        $stmt = $this->db->prepare(
            "SELECT e.id AS employee_id, e.name,
                    s.active_minutes, s.idle_minutes, s.punctuality_flag
             FROM employees e
             LEFT JOIN daily_summary s ON s.employee_id = e.id AND s.date = ?
             WHERE e.status = 'active' AND e.role != 'admin'
             ORDER BY e.name ASC"
        );
        $stmt->execute([$date]);

        return Json::write($response, ['date' => $date, 'summary' => $stmt->fetchAll()]);
    }
}
