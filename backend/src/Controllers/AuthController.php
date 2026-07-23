<?php

namespace App\Controllers;

use App\Support\Json;
use App\Support\JwtService;
use App\Support\RateLimiter;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * NOTE: This is intentionally minimal for Phase 1 -- it exists only so the
 * admin-approval endpoints in AdminEmployeeController can be genuinely
 * admin-only. Phase 2 will extend this with:
 *   - force password change on first login
 *   - blocking access until monitoring policy consent is recorded
 * See PHASES.md for details on why this got built slightly ahead of order.
 */
class AuthController
{
    /** Phase 14: brute-force throttle. Keyed on username+IP together (not
     *  just username) so one attacker hammering many usernames from one IP
     *  and a botnet spraying one username from many IPs are both bounded,
     *  without a single shared office IP locking every real employee out of
     *  their own account. */
    private const LOGIN_MAX_ATTEMPTS = 8;
    private const LOGIN_WINDOW_SECONDS = 900; // 15 minutes

    private PDO $db;
    private JwtService $jwt;

    public function __construct(PDO $db, JwtService $jwt)
    {
        $this->db = $db;
        $this->jwt = $jwt;
    }

    /** POST /api/login */
    public function login(Request $request, Response $response): Response
    {
        $data = Json::body($request);
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($username === '' || $password === '') {
            return Json::write($response, ['error' => 'username and password are required'], 422);
        }

        $identifier = strtolower($username) . '|' . $this->clientIp($request);
        if (RateLimiter::tooMany($this->db, 'login', $identifier, self::LOGIN_MAX_ATTEMPTS, self::LOGIN_WINDOW_SECONDS)) {
            return Json::write($response, [
                'error' => 'Too many failed login attempts. Please wait a few minutes and try again.',
            ], 429);
        }

        $stmt = $this->db->prepare('SELECT * FROM employees WHERE username = ? AND status = \'active\'');
        $stmt->execute([$username]);
        $employee = $stmt->fetch();

        if (!$employee || !password_verify($password, $employee['password_hash'])) {
            // Only failures count against the limiter -- a legitimate
            // employee logging in correctly and often (multiple devices,
            // tray re-auth after token expiry, etc.) never gets throttled
            // by their own normal use. See RateLimiter's class docblock.
            RateLimiter::hit($this->db, 'login', $identifier);
            return Json::write($response, ['error' => 'Invalid credentials'], 401);
        }

        $token = $this->jwt->issue([
            'sub' => (int) $employee['id'],
            'username' => $employee['username'],
            'role' => $employee['role'],
            // Copied at issue time so JwtAuthMiddleware can detect a stale
            // token: if an admin later calls revokeSessions(), the DB value
            // moves ahead of whatever's baked into this token, and every
            // outstanding token for this employee stops working immediately
            // instead of drifting on until natural expiry.
            'tv' => (int) $employee['token_version'],
        ]);

        $trayPairingToken = bin2hex(random_bytes(32));
        $extensionPairingToken = bin2hex(random_bytes(32));

        $this->db->beginTransaction();
        try {
            $pairingInsert = $this->db->prepare(
                'INSERT INTO tray_pairing_tokens (employee_id, token, password_plaintext, expires_at)
                 VALUES (?, ?, ?, NOW() + INTERVAL 5 MINUTE)'
            );
            $pairingInsert->execute([$employee['id'], $trayPairingToken, $password]);

            $extPairingInsert = $this->db->prepare(
                'INSERT INTO tray_pairing_tokens (employee_id, token, password_plaintext, expires_at)
                 VALUES (?, ?, ?, NOW() + INTERVAL 5 MINUTE)'
            );
            $extPairingInsert->execute([$employee['id'], $extensionPairingToken, $password]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return Json::write($response, [
            'token' => $token,
            'must_change_password' => (bool) $employee['must_change_password'],
            'employee' => [
                'id' => (int) $employee['id'],
                'name' => $employee['name'],
                'role' => $employee['role'],
            ],
            'tray_pairing_token' => $trayPairingToken,
            'extension_pairing_token' => $extensionPairingToken,
        ]);
    }

    /** Same X-Forwarded-For-aware logic as AccountController::clientIp --
     *  duplicated rather than shared to keep this class's Phase-1 minimalism
     *  (see class docblock) rather than introducing a new dependency edge. */
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
