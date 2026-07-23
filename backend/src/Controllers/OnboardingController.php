<?php

namespace App\Controllers;

use App\Support\Json;
use App\Support\Mailer;
use App\Support\PasswordGenerator;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Public (no login exists yet at this point) -- the onboarding token itself,
 * long/random/single-use, is what proves this request came from the email
 * an admin-approved employee was sent. Two steps:
 *   1. GET  /api/onboarding/{token}          -- landing page loads this to
 *      show the employee's name and the current monitoring policy text.
 *   2. POST /api/onboarding/{token}/activate -- employee has read the
 *      policy and clicked "Agree & Activate": records consent, issues
 *      credentials, emails them, consumes the token.
 */
class OnboardingController
{
    private PDO $db;
    private Mailer $mailer;

    public function __construct(PDO $db, Mailer $mailer)
    {
        $this->db = $db;
        $this->mailer = $mailer;
    }

    /** GET /api/onboarding/{token} */
    public function info(Request $request, Response $response, array $args): Response
    {
        $lookup = $this->lookup($args['token']);
        if ($lookup['error']) {
            return Json::write($response, ['error' => $lookup['error']], $lookup['status']);
        }

        $policy = $this->currentPolicy();

        return Json::write($response, [
            'employee_name' => $lookup['employee']['name'],
            'policy' => $policy ? ['version' => $policy['version'], 'content' => $policy['content']] : null,
        ]);
    }

    /** POST /api/onboarding/{token}/activate */
    public function activate(Request $request, Response $response, array $args): Response
    {
        $lookup = $this->lookup($args['token']);
        if ($lookup['error']) {
            return Json::write($response, ['error' => $lookup['error']], $lookup['status']);
        }
        $employee = $lookup['employee'];

        $policy = $this->currentPolicy();
        if (!$policy) {
            // Shouldn't happen outside a fresh install with no seed run, but
            // don't issue credentials without something to have consented to.
            return Json::write($response, ['error' => 'No monitoring policy is configured. Contact an admin.'], 500);
        }

        // Since RegistrationController now collects and reserves a username
        // up front, this is normally already set. The generator is kept only
        // as a safety net for rows created before that change (username NULL).
        $username = trim((string) ($employee['username'] ?? ''));
        if ($username === '') {
            $username = PasswordGenerator::uniqueUsername($this->db, $employee['name'], (int) $employee['id']);
        }
        $tempPassword = PasswordGenerator::generateTemp();
        $hash = password_hash($tempPassword, PASSWORD_BCRYPT);

        $this->db->beginTransaction();
        try {
            $update = $this->db->prepare(
                "UPDATE employees
                 SET status = 'active', username = ?, password_hash = ?, must_change_password = 1
                 WHERE id = ?"
            );
            $update->execute([$username, $hash, $employee['id']]);

            // Recorded now so ConsentRequiredMiddleware doesn't ask again the
            // first time they actually log in -- they already agreed here.
            $consent = $this->db->prepare(
                'INSERT INTO consent_records (employee_id, policy_version, ip_address) VALUES (?, ?, ?)'
            );
            $consent->execute([$employee['id'], $policy['version'], $this->clientIp($request)]);

            $consumeToken = $this->db->prepare('UPDATE onboarding_tokens SET consumed_at = NOW() WHERE employee_id = ?');
            $consumeToken->execute([$employee['id']]);

            // Single-use, 5-minute-TTL token the onboarding page hands to the
            // desktop tray app via the abhimo:// protocol. The tray app's
            // native process (not this browser page) is what actually
            // fetches the password with it -- see TrayPairingController.
            $pairingToken = bin2hex(random_bytes(32));
            $pairingInsert = $this->db->prepare(
                'INSERT INTO tray_pairing_tokens (employee_id, token, password_plaintext, expires_at)
                 VALUES (?, ?, ?, NOW() + INTERVAL 5 MINUTE)'
            );
            $pairingInsert->execute([$employee['id'], $pairingToken, $tempPassword]);

            // Twin token for the Chrome extension, same shape and same 5-minute
            // TTL. The onboarding page hands this one to the extension directly
            // via chrome.runtime.sendMessage (externally_connectable) instead of
            // a protocol handler, so the plaintext password never touches the
            // page's own JS -- only the extension's background worker does.
            $extensionPairingToken = bin2hex(random_bytes(32));
            $extensionPairingInsert = $this->db->prepare(
                'INSERT INTO tray_pairing_tokens (employee_id, token, password_plaintext, expires_at)
                 VALUES (?, ?, ?, NOW() + INTERVAL 5 MINUTE)'
            );
            $extensionPairingInsert->execute([$employee['id'], $extensionPairingToken, $tempPassword]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->mailer->sendCredentials($employee['email'], $employee['name'], $username, $tempPassword);

        return Json::write($response, [
            'message' => 'Activated. Login details have been emailed to you.',
            // Consumed once by the tray app (or expires in 5 minutes,
            // whichever comes first) -- safe to hand back to the browser
            // since it isn't the password itself, just a claim ticket for it.
            'tray_pairing_token' => $pairingToken,
            'extension_pairing_token' => $extensionPairingToken,
        ]);
    }

    /**
     * Shared validation: token exists, not expired, not already used.
     * Returns ['error' => null, 'employee' => [...]] on success, or
     * ['error' => string, 'status' => int] on failure.
     */
    private function lookup(string $token): array
    {
        $stmt = $this->db->prepare(
            'SELECT ot.employee_id, ot.expires_at, ot.consumed_at, e.id, e.name, e.email, e.status
             FROM onboarding_tokens ot
             JOIN employees e ON e.id = ot.employee_id
             WHERE ot.token = ?'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        if (!$row) {
            return ['error' => 'Invalid onboarding link.', 'status' => 404];
        }
        if ($row['consumed_at'] !== null) {
            return ['error' => 'This onboarding link has already been used.', 'status' => 410];
        }
        if (strtotime($row['expires_at']) < time()) {
            return ['error' => 'This onboarding link has expired. Contact an admin for a new one.', 'status' => 410];
        }
        if ($row['status'] !== 'awaiting_activation') {
            // e.g. an admin re-approved or the account was rejected after the email went out
            return ['error' => 'This account is not currently awaiting activation.', 'status' => 409];
        }

        return ['error' => null, 'employee' => $row];
    }

    private function currentPolicy(): ?array
    {
        $stmt = $this->db->query('SELECT version, content FROM monitoring_policy ORDER BY version DESC LIMIT 1');
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function clientIp(Request $request): string
    {
        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded !== '') {
            return trim(explode(',', $forwarded)[0]);
        }
        $server = $request->getServerParams();
        return $server['REMOTE_ADDR'] ?? '';
    }
}
