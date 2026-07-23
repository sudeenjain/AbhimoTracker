<?php

namespace App\Controllers;

use App\Support\Json;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Phase 3. Both endpoints sit behind JwtAuthMiddleware + ConsentRequiredMiddleware
 * (see public/index.php) -- an employee physically cannot sign in/out until
 * they've changed their password and accepted the current monitoring policy.
 */
class AttendanceController
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** POST /api/attendance/sign-in */
    public function signIn(Request $request, Response $response): Response
    {
        $auth = $request->getAttribute('auth');
        $employeeId = (int) $auth['sub'];
        $today = date('Y-m-d');

        $stmt = $this->db->prepare('SELECT id, sign_in_time, sign_out_time FROM attendance_logs WHERE employee_id = ? AND date = ?');
        $stmt->execute([$employeeId, $today]);
        $existing = $stmt->fetch();

        if ($existing && $existing['sign_in_time'] !== null && $existing['sign_out_time'] === null) {
            return Json::write($response, ['error' => 'You are already signed in today.', 'sign_in_time' => $existing['sign_in_time']], 409);
        }

        if ($existing && $existing['sign_in_time'] !== null && $existing['sign_out_time'] !== null) {
            return Json::write($response, ['error' => 'You have already completed a sign-in/sign-out for today.'], 409);
        }

        $insert = $this->db->prepare('INSERT INTO attendance_logs (employee_id, sign_in_time, date) VALUES (?, NOW(), ?)');
        $insert->execute([$employeeId, $today]);
        $attendanceId = (int) $this->db->lastInsertId();

        $this->startWorkSession($employeeId, $attendanceId, $today);

        return Json::write($response, [
            'message' => 'Signed in.',
            'attendance_id' => $attendanceId,
            'date' => $today,
        ], 201);
    }

    /**
     * One work session per attendance row (see UNIQUE KEY on
     * employee_work_sessions.attendance_id). Uses INSERT IGNORE so a stray
     * duplicate call (e.g. a race on page refresh) can never create a
     * second session for the same attendance_id -- it silently no-ops.
     */
    private function startWorkSession(int $employeeId, int $attendanceId, string $today): void
    {
        $insert = $this->db->prepare(
            'INSERT IGNORE INTO employee_work_sessions
                (employee_id, attendance_id, session_date, sign_in_time, last_heartbeat_at, last_activity_at, current_status)
             VALUES (?, ?, ?, NOW(), NOW(), NOW(), \'ACTIVE\')'
        );
        $insert->execute([$employeeId, $attendanceId, $today]);
    }

    /** POST /api/attendance/sign-out */
    public function signOut(Request $request, Response $response): Response
    {
        $auth = $request->getAttribute('auth');
        $employeeId = (int) $auth['sub'];
        $today = date('Y-m-d');

        $stmt = $this->db->prepare('SELECT id, sign_in_time, sign_out_time FROM attendance_logs WHERE employee_id = ? AND date = ?');
        $stmt->execute([$employeeId, $today]);
        $existing = $stmt->fetch();

        if (!$existing || $existing['sign_in_time'] === null) {
            return Json::write($response, ['error' => 'You have not signed in today.'], 409);
        }
        if ($existing['sign_out_time'] !== null) {
            return Json::write($response, ['error' => 'You have already signed out today.', 'sign_out_time' => $existing['sign_out_time']], 409);
        }

        $update = $this->db->prepare('UPDATE attendance_logs SET sign_out_time = NOW() WHERE id = ?');
        $update->execute([$existing['id']]);

        $this->closeWorkSession($existing['id']);

        return Json::write($response, ['message' => 'Signed out.', 'date' => $today]);
    }

    /**
     * Folds the gap since the last heartbeat into idle_seconds (an
     * unattended tab between the last heartbeat and clicking "Sign out"
     * isn't active work), stamps sign_out_time, and moves status to
     * SIGNED_OUT. Guarded by the sign_out_time IS NULL check above, so a
     * duplicate/racing sign-out request can't double-close or double-count.
     */
    private function closeWorkSession(int $attendanceId): void
    {
        $stmt = $this->db->prepare('SELECT id, last_heartbeat_at, sign_in_time FROM employee_work_sessions WHERE attendance_id = ?');
        $stmt->execute([$attendanceId]);
        $session = $stmt->fetch();
        if (!$session) {
            return; // no session was ever started for this attendance row
        }

        $now = new \DateTimeImmutable();
        $lastHeartbeat = $session['last_heartbeat_at']
            ? new \DateTimeImmutable($session['last_heartbeat_at'])
            : new \DateTimeImmutable($session['sign_in_time']);
        $tailSeconds = max(0, min($now->getTimestamp() - $lastHeartbeat->getTimestamp(), 90));

        $update = $this->db->prepare(
            "UPDATE employee_work_sessions
             SET sign_out_time = NOW(), current_status = 'SIGNED_OUT', idle_seconds = idle_seconds + ?
             WHERE id = ?"
        );
        $update->execute([$tailSeconds, $session['id']]);
    }

    /** GET /api/attendance/today -- convenience for the frontend button state */
    public function today(Request $request, Response $response): Response
    {
        $auth = $request->getAttribute('auth');
        $employeeId = (int) $auth['sub'];
        $today = date('Y-m-d');

        $stmt = $this->db->prepare('SELECT sign_in_time, sign_out_time FROM attendance_logs WHERE employee_id = ? AND date = ?');
        $stmt->execute([$employeeId, $today]);
        $row = $stmt->fetch();

        return Json::write($response, [
            'date' => $today,
            'sign_in_time' => $row['sign_in_time'] ?? null,
            'sign_out_time' => $row['sign_out_time'] ?? null,
        ]);
    }
}
