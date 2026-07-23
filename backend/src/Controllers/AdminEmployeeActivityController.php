<?php

namespace App\Controllers;

use App\Support\Json;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Phase 10 (+ Phase 12), admin-only. Two views of one employee's tracked
 * activity, built entirely by reading data already captured by
 * ActivityController::ingest, WebsiteActivityController::ingest, and
 * AttendanceController -- this class writes nothing, it only aggregates
 * and orders what's already there:
 *   - detail(): a single day -- attendance, per-application active time,
 *     per-website-domain active time, and a collapsed chronological
 *     timeline.
 *   - range(): Today / Yesterday / Last 7 Days / Custom Date -- per-day
 *     active/idle totals plus top apps/websites aggregated across the
 *     whole range.
 *
 * Deliberately reports only what was actually tracked (time spent per
 * app/domain) -- it never infers or claims a task was "completed" from
 * usage data alone (see Phase 11 in the original spec / hard constraint
 * on not inferring productivity from raw activity).
 */
class AdminEmployeeActivityController
{
    private PDO $db;

    /** Same threshold as AdminActivityController::ONLINE_WINDOW_SECONDS --
     *  kept in sync deliberately so this page and the live dashboard never
     *  disagree about what "currently active" means for the same employee. */
    private const ONLINE_WINDOW_SECONDS = 180;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Widest range a single request will compute, so an admin fat-fingering
     *  ?from=2020-01-01 can't trigger a full-table scan across years of
     *  activity_logs / website_usage_logs. Generous enough for "last 30
     *  days" style views without needing pagination yet. */
    private const MAX_RANGE_DAYS = 31;

    /**
     * GET /api/admin/employees/{id}/activity/range?from=YYYY-MM-DD&to=YYYY-MM-DD
     * Phase 12: backs the Today / Yesterday / Last 7 Days / Custom Date
     * filter on the employee activity page. `to` defaults to today, `from`
     * defaults to 6 days before `to` (i.e. a 7-day window) when omitted, so
     * the "Last 7 Days" quick filter is just this endpoint with no params.
     *
     * Per-day active/idle time prefers the already-aggregated daily_summary
     * row (migration 006, populated by bin/aggregate-daily-summary.php)
     * when one exists, since that's a single indexed row instead of a scan
     * over that day's activity_logs. Falls back to a live SUM over
     * activity_logs for any date without a daily_summary row yet -- always
     * true for today (the nightly cron hasn't run for it), and also covers
     * a day the cron job happened to miss, so history is never silently
     * blank just because the cron didn't fire.
     */
    public function range(Request $request, Response $response, array $args): Response
    {
        $employeeId = (int) $args['id'];
        $params = $request->getQueryParams();
        $to = $params['to'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            return Json::write($response, ['error' => 'to must be in YYYY-MM-DD format'], 422);
        }
        $from = $params['from'] ?? (new \DateTimeImmutable($to))->modify('-6 days')->format('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            return Json::write($response, ['error' => 'from must be in YYYY-MM-DD format'], 422);
        }
        if ($from > $to) {
            return Json::write($response, ['error' => 'from must not be after to'], 422);
        }
        $spanDays = (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days + 1;
        if ($spanDays > self::MAX_RANGE_DAYS) {
            return Json::write($response, ['error' => 'Date range cannot exceed ' . self::MAX_RANGE_DAYS . ' days.'], 422);
        }

        $employeeStmt = $this->db->prepare(
            "SELECT id, name, role FROM employees WHERE id = ? AND role != 'admin'"
        );
        $employeeStmt->execute([$employeeId]);
        $employee = $employeeStmt->fetch();
        if (!$employee) {
            return Json::write($response, ['error' => 'Employee not found.'], 404);
        }

        $attendanceStmt = $this->db->prepare(
            'SELECT date, sign_in_time, sign_out_time FROM attendance_logs
             WHERE employee_id = ? AND date BETWEEN ? AND ?'
        );
        $attendanceStmt->execute([$employeeId, $from, $to]);
        $attendanceByDate = [];
        foreach ($attendanceStmt->fetchAll() as $row) {
            $attendanceByDate[$row['date']] = $row;
        }

        $summaryStmt = $this->db->prepare(
            'SELECT date, active_minutes, idle_minutes FROM daily_summary
             WHERE employee_id = ? AND date BETWEEN ? AND ?'
        );
        $summaryStmt->execute([$employeeId, $from, $to]);
        $summaryByDate = [];
        foreach ($summaryStmt->fetchAll() as $row) {
            $summaryByDate[$row['date']] = [
                'active_seconds' => ((int) $row['active_minutes']) * 60,
                'idle_seconds' => ((int) $row['idle_minutes']) * 60,
            ];
        }

        // Live fallback -- only for dates daily_summary didn't already
        // cover, so this never re-scans days the cron already aggregated.
        $missingDates = array_values(array_diff($this->dateRange($from, $to), array_keys($summaryByDate)));
        $liveByDate = [];
        if ($missingDates !== []) {
            $placeholders = implode(',', array_fill(0, count($missingDates), '?'));
            $liveStmt = $this->db->prepare(
                "SELECT DATE(timestamp) AS date,
                    COALESCE(SUM(CASE WHEN is_idle = 0 THEN duration_seconds ELSE 0 END), 0) AS active_seconds,
                    COALESCE(SUM(CASE WHEN is_idle = 1 THEN duration_seconds ELSE 0 END), 0) AS idle_seconds
                 FROM activity_logs
                 WHERE employee_id = ? AND DATE(timestamp) IN ($placeholders)
                 GROUP BY DATE(timestamp)"
            );
            $liveStmt->execute(array_merge([$employeeId], $missingDates));
            foreach ($liveStmt->fetchAll() as $row) {
                $liveByDate[$row['date']] = [
                    'active_seconds' => (int) $row['active_seconds'],
                    'idle_seconds' => (int) $row['idle_seconds'],
                ];
            }
        }

        $days = [];
        $totalActive = 0;
        $totalIdle = 0;
        foreach ($this->dateRange($from, $to) as $date) {
            $totals = $summaryByDate[$date] ?? $liveByDate[$date] ?? ['active_seconds' => 0, 'idle_seconds' => 0];
            $attendance = $attendanceByDate[$date] ?? ['sign_in_time' => null, 'sign_out_time' => null];
            $totalActive += $totals['active_seconds'];
            $totalIdle += $totals['idle_seconds'];
            $days[] = [
                'date' => $date,
                'sign_in_time' => $attendance['sign_in_time'],
                'sign_out_time' => $attendance['sign_out_time'],
                'active_seconds' => $totals['active_seconds'],
                'idle_seconds' => $totals['idle_seconds'],
            ];
        }

        // Top apps/websites aggregated across the whole range -- capped at
        // 10 rows each; this is a summary view, not a full breakdown (that
        // remains the single-date detail() endpoint's job).
        $appsStmt = $this->db->prepare(
            "SELECT active_window AS app, SUM(duration_seconds) AS total_seconds
             FROM activity_logs
             WHERE employee_id = ? AND DATE(timestamp) BETWEEN ? AND ? AND is_idle = 0 AND active_window IS NOT NULL
             GROUP BY active_window
             ORDER BY total_seconds DESC
             LIMIT 10"
        );
        $appsStmt->execute([$employeeId, $from, $to]);
        $applications = array_map(
            static fn($r) => ['app' => $r['app'], 'total_seconds' => (int) $r['total_seconds']],
            $appsStmt->fetchAll()
        );

        $sitesStmt = $this->db->prepare(
            "SELECT domain, SUM(duration_seconds) AS total_seconds
             FROM website_usage_logs
             WHERE employee_id = ? AND DATE(timestamp) BETWEEN ? AND ? AND is_idle = 0 AND domain IS NOT NULL
             GROUP BY domain
             ORDER BY total_seconds DESC
             LIMIT 10"
        );
        $sitesStmt->execute([$employeeId, $from, $to]);
        $websites = array_map(
            static fn($r) => ['domain' => $r['domain'], 'total_seconds' => (int) $r['total_seconds']],
            $sitesStmt->fetchAll()
        );

        return Json::write($response, [
            'employee' => ['id' => (int) $employee['id'], 'name' => $employee['name'], 'role' => $employee['role']],
            'from' => $from,
            'to' => $to,
            'days' => $days,
            'totals' => [
                'active_seconds' => $totalActive,
                'idle_seconds' => $totalIdle,
            ],
            'applications' => $applications,
            'websites' => $websites,
        ]);
    }

    /** @return string[] every date from $from to $to inclusive, Y-m-d. */
    private function dateRange(string $from, string $to): array
    {
        $out = [];
        $cursor = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable($to);
        while ($cursor <= $end) {
            $out[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }
        return $out;
    }

    /** GET /api/admin/employees/{id}/activity?date=YYYY-MM-DD (defaults to today) */
    public function detail(Request $request, Response $response, array $args): Response
    {
        $employeeId = (int) $args['id'];
        $date = $request->getQueryParams()['date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return Json::write($response, ['error' => 'date must be in YYYY-MM-DD format'], 422);
        }

        $employeeStmt = $this->db->prepare(
            "SELECT id, name, role FROM employees WHERE id = ? AND role != 'admin'"
        );
        $employeeStmt->execute([$employeeId]);
        $employee = $employeeStmt->fetch();
        if (!$employee) {
            return Json::write($response, ['error' => 'Employee not found.'], 404);
        }

        $attendanceStmt = $this->db->prepare(
            'SELECT sign_in_time, sign_out_time FROM attendance_logs WHERE employee_id = ? AND date = ?'
        );
        $attendanceStmt->execute([$employeeId, $date]);
        $attendance = $attendanceStmt->fetch() ?: ['sign_in_time' => null, 'sign_out_time' => null];

        // Active/idle totals -- same calculation as bin/aggregate-daily-summary.php,
        // computed live here rather than read from daily_summary so "today"
        // is never stale waiting on the nightly cron job to run.
        $totalsStmt = $this->db->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN is_idle = 0 THEN duration_seconds ELSE 0 END), 0) AS active_seconds,
                COALESCE(SUM(CASE WHEN is_idle = 1 THEN duration_seconds ELSE 0 END), 0) AS idle_seconds
             FROM activity_logs
             WHERE employee_id = ? AND DATE(timestamp) = ?"
        );
        $totalsStmt->execute([$employeeId, $date]);
        $totals = $totalsStmt->fetch();
        $activeSeconds = (int) $totals['active_seconds'];
        $idleSeconds = (int) $totals['idle_seconds'];

        $appsStmt = $this->db->prepare(
            "SELECT active_window AS app, SUM(duration_seconds) AS total_seconds
             FROM activity_logs
             WHERE employee_id = ? AND DATE(timestamp) = ? AND is_idle = 0 AND active_window IS NOT NULL
             GROUP BY active_window
             ORDER BY total_seconds DESC"
        );
        $appsStmt->execute([$employeeId, $date]);
        $applications = array_map(
            static fn($r) => ['app' => $r['app'], 'total_seconds' => (int) $r['total_seconds']],
            $appsStmt->fetchAll()
        );

        $sitesStmt = $this->db->prepare(
            "SELECT domain, SUM(duration_seconds) AS total_seconds
             FROM website_usage_logs
             WHERE employee_id = ? AND DATE(timestamp) = ? AND is_idle = 0 AND domain IS NOT NULL
             GROUP BY domain
             ORDER BY total_seconds DESC"
        );
        $sitesStmt->execute([$employeeId, $date]);
        $websites = array_map(
            static fn($r) => ['domain' => $r['domain'], 'total_seconds' => (int) $r['total_seconds']],
            $sitesStmt->fetchAll()
        );

        return Json::write($response, [
            'employee' => ['id' => (int) $employee['id'], 'name' => $employee['name'], 'role' => $employee['role']],
            'date' => $date,
            'attendance' => [
                'sign_in_time' => $attendance['sign_in_time'],
                'sign_out_time' => $attendance['sign_out_time'],
                'status' => $this->currentStatus($employeeId, $date, $attendance),
            ],
            'totals' => [
                'total_session_seconds' => $activeSeconds + $idleSeconds,
                'active_seconds' => $activeSeconds,
                'idle_seconds' => $idleSeconds,
            ],
            'applications' => $applications,
            'websites' => $websites,
            'timeline' => $this->buildTimeline($employeeId, $date, $attendance),
        ]);
    }

    /**
     * 'working' / 'idle' / 'offline' only ever apply to *today* -- a past
     * date is always a static 'completed' or 'absent', since polling for
     * "currently active" on a day that already ended is meaningless.
     */
    private function currentStatus(int $employeeId, string $date, array $attendance): string
    {
        if ($date !== date('Y-m-d')) {
            return $attendance['sign_in_time'] ? 'completed' : 'absent';
        }
        if (!$attendance['sign_in_time']) {
            return 'not_signed_in';
        }
        if ($attendance['sign_out_time']) {
            return 'signed_out';
        }

        $lastStmt = $this->db->prepare(
            'SELECT timestamp, is_idle FROM activity_logs WHERE employee_id = ? ORDER BY id DESC LIMIT 1'
        );
        $lastStmt->execute([$employeeId]);
        $last = $lastStmt->fetch();
        if (!$last) {
            return 'working'; // signed in, agent hasn't sent its first entry yet
        }

        $secondsAgo = (new \DateTimeImmutable())->getTimestamp()
            - (new \DateTimeImmutable($last['timestamp']))->getTimestamp();
        if ($secondsAgo > self::ONLINE_WINDOW_SECONDS) {
            return 'offline';
        }
        return ((int) $last['is_idle'] === 1) ? 'idle' : 'active';
    }

    /**
     * Builds one chronological list out of three sources: sign-in/out
     * events, activity_logs (desktop app/idle), and website_usage_logs
     * (browser domain/idle). Both log tables are written in ~90s chunks
     * (see activity-tracker.js / browser-extension/background.js), so a
     * naive row-per-entry timeline would repeat the same app or domain
     * every ~90 seconds all day -- collapseSegments() merges consecutive
     * rows with the same value into a single entry spanning from the
     * first chunk's start to the last chunk's end, which is what actually
     * shows up as one line in the timeline.
     */
    private function buildTimeline(int $employeeId, string $date, array $attendance): array
    {
        $events = [];

        if ($attendance['sign_in_time']) {
            $events[] = ['time' => $attendance['sign_in_time'], 'sort' => 0, 'type' => 'sign_in', 'label' => 'Signed in'];
        }
        if ($attendance['sign_out_time']) {
            $events[] = ['time' => $attendance['sign_out_time'], 'sort' => 2, 'type' => 'sign_out', 'label' => 'Signed out'];
        }

        $appStmt = $this->db->prepare(
            'SELECT timestamp, active_window, is_idle FROM activity_logs
             WHERE employee_id = ? AND DATE(timestamp) = ? ORDER BY timestamp ASC, id ASC'
        );
        $appStmt->execute([$employeeId, $date]);
        $events = array_merge($events, $this->collapseSegments($appStmt->fetchAll(), 'app'));

        $siteStmt = $this->db->prepare(
            'SELECT timestamp, domain, is_idle FROM website_usage_logs
             WHERE employee_id = ? AND DATE(timestamp) = ? ORDER BY timestamp ASC, id ASC'
        );
        $siteStmt->execute([$employeeId, $date]);
        $events = array_merge($events, $this->collapseSegments($siteStmt->fetchAll(), 'website'));

        usort($events, static fn($a, $b) => [$a['time'], $a['sort']] <=> [$b['time'], $b['sort']]);

        return array_map(
            static fn($e) => ['time' => $e['time'], 'type' => $e['type'], 'label' => $e['label']],
            $events
        );
    }

    /**
     * @param array $rows rows with keys: timestamp, (active_window|domain), is_idle
     * @param string $kind 'app' or 'website' -- selects which column holds
     *   the value and how the resulting label reads.
     */
    private function collapseSegments(array $rows, string $kind): array
    {
        $valueKey = $kind === 'app' ? 'active_window' : 'domain';
        $segments = [];
        $current = null;

        foreach ($rows as $row) {
            $isIdle = (bool) $row['is_idle'];
            $value = $row[$valueKey];
            $key = $isIdle ? 'idle' : $value;

            if ($current !== null && $current['key'] === $key) {
                continue; // still the same segment -- start time already recorded
            }
            $current = ['key' => $key, 'value' => $value, 'is_idle' => $isIdle, 'start' => $row['timestamp']];
            $segments[] = $current;
        }

        $sortRank = 1; // strictly between sign_in (0) and sign_out (2)
        $out = [];
        foreach ($segments as $seg) {
            if ($seg['is_idle']) {
                // The desktop-app idle stream (kind === 'app') is treated as
                // authoritative for "Idle" in the timeline -- a simultaneous
                // idle stretch reported by the browser extension would
                // otherwise show up as a second, redundant "Idle" line.
                if ($kind !== 'app') {
                    continue;
                }
                $label = 'Idle';
                $type = 'idle';
            } elseif ($kind === 'app') {
                $label = $seg['value'] ?: 'Unknown application';
                $type = 'app';
            } else {
                $label = 'Visited ' . ($seg['value'] ?: 'unknown site');
                $type = 'website';
            }
            $out[] = ['time' => $seg['start'], 'sort' => $sortRank, 'type' => $type, 'label' => $label];
        }
        return $out;
    }
}
