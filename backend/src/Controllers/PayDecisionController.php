<?php

namespace App\Controllers;

use App\Support\Json;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Phase 4. Admin-only (see AdminOnlyMiddleware in public/index.php).
 *
 * Hard constraint #3: pay/incentive amounts are NEVER computed by this
 * system. This controller only ever records a human admin's decision --
 * who decided, what they decided, why, and when. It deliberately does NOT
 * read daily_summary or attendance_logs to suggest or pre-fill an amount;
 * the admin looks at that data themselves (elsewhere in the UI) and types
 * a number in.
 */
class PayDecisionController
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * POST /api/admin/pay-decisions
     * Body: { "employee_id": 5, "period": "2026-07", "amount": 15000.00, "notes": "..." }
     */
    public function create(Request $request, Response $response): Response
    {
        $auth = $request->getAttribute('auth');
        $adminId = (int) $auth['sub'];

        $body = Json::body($request);
        $employeeId = (int) ($body['employee_id'] ?? 0);
        $period = trim((string) ($body['period'] ?? ''));
        $amount = $body['amount'] ?? null;
        $notes = isset($body['notes']) ? (string) $body['notes'] : null;

        if ($employeeId <= 0) {
            return Json::write($response, ['error' => 'employee_id is required'], 422);
        }
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            return Json::write($response, ['error' => 'period must be in YYYY-MM format'], 422);
        }
        if (!is_numeric($amount) || (float) $amount < 0) {
            return Json::write($response, ['error' => 'amount must be a non-negative number'], 422);
        }

        $check = $this->db->prepare("SELECT id FROM employees WHERE id = ? AND status = 'active'");
        $check->execute([$employeeId]);
        if (!$check->fetch()) {
            return Json::write($response, ['error' => 'employee not found'], 404);
        }

        $insert = $this->db->prepare(
            'INSERT INTO pay_decisions (employee_id, period, amount, decided_by_admin_id, notes)
             VALUES (?, ?, ?, ?, ?)'
        );
        $insert->execute([$employeeId, $period, (float) $amount, $adminId, $notes]);

        return Json::write($response, [
            'message' => 'Pay decision recorded.',
            'id' => (int) $this->db->lastInsertId(),
        ], 201);
    }

    /**
     * GET /api/admin/pay-decisions?employee_id=5&period=2026-07
     * Both filters optional; omit either to widen the search.
     */
    public function list(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $employeeId = isset($params['employee_id']) ? (int) $params['employee_id'] : null;
        $period = $params['period'] ?? null;

        if ($period !== null && !preg_match('/^\d{4}-\d{2}$/', $period)) {
            return Json::write($response, ['error' => 'period must be in YYYY-MM format'], 422);
        }

        $sql = 'SELECT pd.id, pd.employee_id, e.name AS employee_name, pd.period, pd.amount,
                       pd.decided_by_admin_id, a.name AS decided_by_name, pd.notes, pd.decided_at
                FROM pay_decisions pd
                JOIN employees e ON e.id = pd.employee_id
                JOIN employees a ON a.id = pd.decided_by_admin_id
                WHERE 1=1';
        $args = [];
        if ($employeeId) {
            $sql .= ' AND pd.employee_id = ?';
            $args[] = $employeeId;
        }
        if ($period) {
            $sql .= ' AND pd.period = ?';
            $args[] = $period;
        }
        $sql .= ' ORDER BY pd.decided_at DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($args);

        return Json::write($response, ['pay_decisions' => $stmt->fetchAll()]);
    }
}
