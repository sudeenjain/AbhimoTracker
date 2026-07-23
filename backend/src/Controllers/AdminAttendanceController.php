<?php

namespace App\Controllers;

use App\Support\Json;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminAttendanceController
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** GET /api/admin/attendance?date=YYYY-MM-DD (defaults to today) */
    public function forDate(Request $request, Response $response): Response
    {
        $date = $request->getQueryParams()['date'] ?? date('Y-m-d');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return Json::write($response, ['error' => 'date must be in YYYY-MM-DD format'], 422);
        }

        // role != 'admin' matches AdminActivityController and
        // AdminWorkSessionController's queries -- without it, an admin
        // account shows up in this table but silently vanishes from the
        // Live status / App usage panels lower on the same admin-attendance
        // page (both of which do filter it out), which reads as a bug.
        $stmt = $this->db->prepare(
            'SELECT e.id AS employee_id, e.name, e.role,
                    a.sign_in_time, a.sign_out_time
             FROM employees e
             LEFT JOIN attendance_logs a ON a.employee_id = e.id AND a.date = ?
             WHERE e.status = \'active\' AND e.role != \'admin\'
             ORDER BY e.name ASC'
        );
        $stmt->execute([$date]);

        return Json::write($response, ['date' => $date, 'attendance' => $stmt->fetchAll()]);
    }
}
