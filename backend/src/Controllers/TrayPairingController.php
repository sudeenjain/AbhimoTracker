<?php

namespace App\Controllers;

use App\Support\Json;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Public (no JWT -- the tray app doesn't have one yet at this point). The
 * pairing token itself, minted by OnboardingController::activate() or
 * AccountController::changePassword(), is what proves this call came from
 * the abhimo:// link the browser just launched, not a stray request.
 *
 * The whole point of this indirection is that the plaintext password never
 * appears in the abhimo://pair?token=... URL that the OS hands to the tray
 * app -- only this token does. The tray app's own HTTP client (not the OS
 * URL handler, not the browser) is what actually receives the password,
 * over a normal JSON response body.
 */
class TrayPairingController
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** POST /api/tray/pair/{token}/exchange */
    public function exchange(Request $request, Response $response, array $args): Response
    {
        $token = (string) $args['token'];

        $stmt = $this->db->prepare(
            'SELECT tpt.id, tpt.employee_id, tpt.password_plaintext, tpt.expires_at, tpt.consumed_at,
                    e.username, e.status
             FROM tray_pairing_tokens tpt
             JOIN employees e ON e.id = tpt.employee_id
             WHERE tpt.token = ?'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        if (!$row) {
            return Json::write($response, ['error' => 'Invalid pairing link.'], 404);
        }
        if ($row['consumed_at'] !== null) {
            return Json::write($response, ['error' => 'This pairing link has already been used.'], 410);
        }
        if (strtotime($row['expires_at']) < time()) {
            return Json::write($response, ['error' => 'This pairing link has expired. Reopen the tray app from the onboarding page (or after your next password change) to get a new one.'], 410);
        }
        if ($row['status'] !== 'active') {
            return Json::write($response, ['error' => 'This account is not active.'], 409);
        }

        // Consume immediately -- one exchange per token, no matter what the
        // tray app does with the response afterward. Also wipes the
        // plaintext password out of this table; it never sits here past
        // this single read.
        $consume = $this->db->prepare(
            'UPDATE tray_pairing_tokens SET consumed_at = NOW(), password_plaintext = NULL WHERE id = ?'
        );
        $consume->execute([$row['id']]);

        return Json::write($response, [
            'username' => $row['username'],
            'password' => $row['password_plaintext'],
        ]);
    }
}
