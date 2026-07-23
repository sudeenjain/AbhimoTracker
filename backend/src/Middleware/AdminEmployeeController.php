<?php

namespace App\Controllers;

use App\Support\Json;
use App\Support\Mailer;
use App\Support\PasswordGenerator;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminEmployeeController
{
    private PDO $db;
    private Mailer $mailer;

    public function __construct(PDO $db, Mailer $mailer)
    {
        $this->db = $db;
        $this->mailer = $mailer;
    }

    /** GET /api/admin/employees?status=pending (default: pending) */
    public function list(Request $request, Response $response): Response
    {
        $status = $request->getQueryParams()['status'] ?? 'pending';
        $allowed = ['pending', 'awaiting_activation', 'active', 'rejected'];
        if (!in_array($status, $allowed, true)) {
            return Json::write($response, ['error' => 'status must be one of: ' . implode(', ', $allowed)], 422);
        }

        $stmt = $this->db->prepare(
            'SELECT id, name, email, phone, role, availability_preference, status, created_at
             FROM employees WHERE status = ? ORDER BY created_at ASC'
        );
        $stmt->execute([$status]);

        return Json::write($response, ['employees' => $stmt->fetchAll()]);
    }

    /**
     * POST /api/admin/employees/{id}/approve
     * No longer issues credentials directly (see OnboardingController for
     * that, hard constraint #4 has moved there). This step only flips the
     * employee to 'awaiting_activation' and emails them a one-time link to
     * the onboarding landing page, where they download the tracker and
     * accept the monitoring policy -- credentials are issued only after that.
     */
    public function approve(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $stmt = $this->db->prepare('SELECT * FROM employees WHERE id = ?');
        $stmt->execute([$id]);
        $employee = $stmt->fetch();

        if (!$employee) {
            return Json::write($response, ['error' => 'Employee not found'], 404);
        }
        if ($employee['status'] !== 'pending') {
            return Json::write($response, ['error' => 'Employee is not in pending status'], 409);
        }

        $token = bin2hex(random_bytes(32));
        $tokenInsert = $this->db->prepare(
            'INSERT INTO onboarding_tokens (employee_id, token, expires_at)
             VALUES (?, ?, NOW() + INTERVAL 7 DAY)
             ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at), consumed_at = NULL'
        );
        $tokenInsert->execute([$id, $token]);

        $update = $this->db->prepare("UPDATE employees SET status = 'awaiting_activation' WHERE id = ?");
        $update->execute([$id]);

        $landingUrl = env('FRONTEND_BASE_URL', 'http://127.0.0.1:8090') . '/abhimo-tracker.html?token=' . $token;
        $this->mailer->sendOnboardingLink($employee['email'], $employee['name'], $landingUrl);

        return Json::write($response, [
            'message' => 'Employee approved. Onboarding link sent.',
            'employee_id' => $id,
            // Dev-only convenience, same reasoning as the old temp_password_dev_only
            // field: lets you test the flow without an SMTP server. Would be
            // removed in a real deployment -- the employee gets this only via email.
            'onboarding_link_dev_only' => $landingUrl,
        ]);
    }

    /**
     * POST /api/admin/employees/{id}/revoke-sessions
     * Immediately invalidates every JWT already issued to this employee,
     * without touching their password or status -- e.g. a lost device, or
     * "sign this person out everywhere right now" without also having to
     * reset credentials. JwtAuthMiddleware rejects any token whose "tv"
     * claim no longer matches this row, which after this UPDATE is every
     * token issued before this moment. A fresh login (new token_version
     * baked into the new token) works immediately after.
     */
    public function revokeSessions(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $stmt = $this->db->prepare('SELECT id, status FROM employees WHERE id = ?');
        $stmt->execute([$id]);
        $employee = $stmt->fetch();

        if (!$employee) {
            return Json::write($response, ['error' => 'Employee not found'], 404);
        }
        if ($employee['status'] !== 'active') {
            return Json::write($response, ['error' => 'Employee has no active session to revoke.'], 409);
        }

        $update = $this->db->prepare('UPDATE employees SET token_version = token_version + 1 WHERE id = ?');
        $update->execute([$id]);

        return Json::write($response, ['message' => 'All sessions for this employee have been revoked.', 'employee_id' => $id]);
    }

    /** POST /api/admin/employees/{id}/reject */
    public function reject(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $stmt = $this->db->prepare('SELECT id, status FROM employees WHERE id = ?');
        $stmt->execute([$id]);
        $employee = $stmt->fetch();

        if (!$employee) {
            return Json::write($response, ['error' => 'Employee not found'], 404);
        }
        if ($employee['status'] !== 'pending') {
            return Json::write($response, ['error' => 'Employee is not in pending status'], 409);
        }

        $update = $this->db->prepare('UPDATE employees SET status = \'rejected\' WHERE id = ?');
        $update->execute([$id]);

        return Json::write($response, ['message' => 'Employee rejected.', 'employee_id' => $id]);
    }
}
