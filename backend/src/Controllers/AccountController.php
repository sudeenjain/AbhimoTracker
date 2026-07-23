<?php

namespace App\Controllers;

use App\Support\Json;
use App\Support\PolicyRepository;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Phase 2: forced password change on first login, then monitoring-policy
 * consent. Order is enforced server-side, not just by the frontend:
 *   1. changePassword() -- required first; sets must_change_password = 0
 *   2. currentPolicy() / acceptConsent() -- acceptConsent() refuses to run
 *      until must_change_password is already 0.
 * ConsentRequiredMiddleware then gates every employee-facing app route
 * behind "password changed AND consent recorded for the current version".
 */
class AccountController
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** POST /api/account/change-password (auth required) */
    public function changePassword(Request $request, Response $response): Response
    {
        $auth = $request->getAttribute('auth');
        $employeeId = (int) $auth['sub'];

        $data = Json::body($request);
        // Same reasoning as AuthController::login -- current_password is
        // typically copy-pasted from the credentials email, so trim it to
        // avoid a trailing space/newline causing a false "incorrect
        // password". new_password is trimmed too, so nobody sets a password
        // with invisible whitespace they didn't mean to include and locks
        // themselves out next time.
        $current = trim((string) ($data['current_password'] ?? ''));
        $new = trim((string) ($data['new_password'] ?? ''));

        if ($current === '' || $new === '') {
            return Json::write($response, ['error' => 'current_password and new_password are required'], 422);
        }
        if (strlen($new) < 8) {
            return Json::write($response, ['error' => 'new_password must be at least 8 characters'], 422);
        }
        if ($new === $current) {
            return Json::write($response, ['error' => 'new_password must be different from current_password'], 422);
        }

        $stmt = $this->db->prepare('SELECT password_hash FROM employees WHERE id = ?');
        $stmt->execute([$employeeId]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($current, $row['password_hash'])) {
            return Json::write($response, ['error' => 'current_password is incorrect'], 401);
        }

        $hash = password_hash($new, PASSWORD_BCRYPT);
        $update = $this->db->prepare('UPDATE employees SET password_hash = ?, must_change_password = 0 WHERE id = ?');
        $update->execute([$hash, $employeeId]);

        // Same claim-ticket pattern as OnboardingController::activate(): the
        // tray app already paired once at activation, but its locally-saved
        // password just went stale. Mint a fresh single-use token carrying
        // the new plaintext password so the browser can hand it off via
        // abhimo:// without ever putting the password itself in a URL.
        $pairingToken = bin2hex(random_bytes(32));
        $pairingInsert = $this->db->prepare(
            'INSERT INTO tray_pairing_tokens (employee_id, token, password_plaintext, expires_at)
             VALUES (?, ?, ?, NOW() + INTERVAL 5 MINUTE)'
        );
        $pairingInsert->execute([$employeeId, $pairingToken, $new]);

        // Twin token for the Chrome extension -- same reasoning, just handed
        // off via chrome.runtime.sendMessage from the onboarding page instead
        // of the abhimo:// protocol. See ExtensionPairingController's route.
        $extensionPairingToken = bin2hex(random_bytes(32));
        $extensionPairingInsert = $this->db->prepare(
            'INSERT INTO tray_pairing_tokens (employee_id, token, password_plaintext, expires_at)
             VALUES (?, ?, ?, NOW() + INTERVAL 5 MINUTE)'
        );
        $extensionPairingInsert->execute([$employeeId, $extensionPairingToken, $new]);

        $policy = PolicyRepository::current($this->db);
        $needsConsent = $policy && !PolicyRepository::hasConsentedToCurrent($this->db, $employeeId);

        return Json::write($response, [
            'message' => 'Password changed.',
            'needs_consent' => $needsConsent,
            'tray_pairing_token' => $pairingToken,
            'extension_pairing_token' => $extensionPairingToken,
        ]);
    }

    /** GET /api/policy/current (auth required) */
    public function currentPolicy(Request $request, Response $response): Response
    {
        $policy = PolicyRepository::current($this->db);
        if (!$policy) {
            return Json::write($response, ['error' => 'No monitoring policy has been configured yet.'], 500);
        }

        return Json::write($response, [
            'version' => (int) $policy['version'],
            'content' => $policy['content'],
            'effective_date' => $policy['effective_date'],
        ]);
    }

    /** POST /api/consent/accept (auth required) */
    public function acceptConsent(Request $request, Response $response): Response
    {
        $auth = $request->getAttribute('auth');
        $employeeId = (int) $auth['sub'];

        $stmt = $this->db->prepare('SELECT must_change_password FROM employees WHERE id = ?');
        $stmt->execute([$employeeId]);
        $employee = $stmt->fetch();

        if (!$employee) {
            return Json::write($response, ['error' => 'Employee not found'], 404);
        }
        if ((int) $employee['must_change_password'] === 1) {
            return Json::write($response, ['error' => 'You must change your password before accepting the monitoring policy.'], 409);
        }

        $policy = PolicyRepository::current($this->db);
        if (!$policy) {
            return Json::write($response, ['error' => 'No monitoring policy has been configured yet.'], 500);
        }

        $data = Json::body($request);
        $submittedVersion = isset($data['policy_version']) ? (int) $data['policy_version'] : null;
        if ($submittedVersion !== null && $submittedVersion !== (int) $policy['version']) {
            return Json::write($response, ['error' => 'A newer monitoring policy version is now in effect. Please re-read and accept the current version.'], 409);
        }

        $ip = $this->clientIp($request);

        $stmt = $this->db->prepare(
            'INSERT INTO consent_records (employee_id, policy_version, accepted_at, ip_address)
             VALUES (?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE accepted_at = accepted_at'
        );
        $stmt->execute([$employeeId, $policy['version'], $ip]);

        return Json::write($response, [
            'message' => 'Consent recorded. You may now use the app.',
            'policy_version' => (int) $policy['version'],
        ]);
    }

    /**
     * POST /api/consent/withdraw (auth required)
     * Phase 13: "if an employee withdraws consent, stop new monitoring."
     * Marks the employee's consent_records row for the CURRENT policy
     * version as withdrawn (see migration 018) rather than deleting it --
     * the original acceptance stays as a historical record. From the next
     * poll/ingest onward, PolicyRepository::hasConsentedToCurrent() returns
     * false for this employee, so ConsentRequiredMiddleware immediately
     * blocks attendance, activity ingest, and work-session routes with the
     * same 403 { reason: "consent_required" } shape used before consent was
     * ever given -- the desktop tray and browser extension already handle
     * that response by stopping local tracking (see
     * desktop-tray/main.js::pollMonitoringStatus).
     *
     * Does not sign the employee out or touch attendance_logs -- withdrawal
     * only stops *new* monitoring data from being recorded, per spec; it is
     * not an attendance action.
     */
    public function withdrawConsent(Request $request, Response $response): Response
    {
        $auth = $request->getAttribute('auth');
        $employeeId = (int) $auth['sub'];

        $policy = PolicyRepository::current($this->db);
        if (!$policy) {
            return Json::write($response, ['error' => 'No monitoring policy has been configured yet.'], 500);
        }

        $stmt = $this->db->prepare(
            'UPDATE consent_records SET withdrawn_at = NOW()
             WHERE employee_id = ? AND policy_version = ? AND withdrawn_at IS NULL'
        );
        $stmt->execute([$employeeId, $policy['version']]);

        if ($stmt->rowCount() === 0) {
            // Either never consented to the current version, or already
            // withdrawn -- either way the end state (not consented) is what
            // the caller wanted, so this is a success, not an error.
            return Json::write($response, [
                'message' => 'Consent was not currently active for the monitoring policy; no change made.',
                'consented' => false,
            ]);
        }

        return Json::write($response, [
            'message' => 'Monitoring consent withdrawn. New activity tracking will stop.',
            'consented' => false,
        ]);
    }

    private function clientIp(Request $request): string
    {
        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded !== '') {
            return trim(explode(',', $forwarded)[0]);
        }
        $server = $request->getServerParams();
        return $server['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
