<?php

namespace App\Middleware;

use App\Support\JwtService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as Psr7Response;

/**
 * Validates the Bearer token on the request and attaches the decoded
 * claims to the request as the "auth" attribute for downstream handlers.
 * Does NOT check role -- see AdminOnlyMiddleware for that.
 *
 * Also enforces server-side revocation: every token carries the employee's
 * token_version at issue time (claim "tv"). We compare that against the
 * current DB value on every request, so admin-triggered revocation (see
 * AdminEmployeeController::revokeSessions) takes effect immediately instead
 * of waiting out the token's natural expiry. Same query also re-checks the
 * employee is still 'active', so a token stops working the moment either a
 * revoke or a status change happens -- both are cheap, since it's a single
 * indexed row lookup already required for the token_version check.
 */
class JwtAuthMiddleware
{
    private JwtService $jwt;
    private PDO $db;

    public function __construct(JwtService $jwt, PDO $db)
    {
        $this->jwt = $jwt;
        $this->db = $db;
    }

    public function __invoke(Request $request, Handler $handler): Response
    {
        $header = $request->getHeaderLine('Authorization');

        if (!$header || !str_starts_with($header, 'Bearer ')) {
            return $this->unauthorized('Missing bearer token.');
        }

        $token = substr($header, 7);

        try {
            $claims = $this->jwt->verify($token);
        } catch (\Throwable $e) {
            return $this->unauthorized('Invalid or expired token.');
        }

        $stmt = $this->db->prepare('SELECT token_version, status FROM employees WHERE id = ?');
        $stmt->execute([(int) ($claims['sub'] ?? 0)]);
        $employee = $stmt->fetch();

        $tokenTv = (int) ($claims['tv'] ?? -1);
        if (!$employee || $employee['status'] !== 'active' || (int) $employee['token_version'] !== $tokenTv) {
            return $this->unauthorized('Session has been revoked. Please log in again.');
        }

        $request = $request->withAttribute('auth', $claims);

        return $handler->handle($request);
    }

    private function unauthorized(string $message): Response
    {
        $response = new Psr7Response(401);
        $response->getBody()->write(json_encode(['error' => $message]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
