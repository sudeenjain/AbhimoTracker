<?php

namespace App\Middleware;

use App\Support\PolicyRepository;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as Psr7Response;

/**
 * Must run AFTER JwtAuthMiddleware. Blocks every employee-facing route
 * (attendance, and later Phase 4 activity ingest) until:
 *   1. the employee has changed their temp password, AND
 *   2. the employee has a consent_records row for the CURRENT policy version.
 * Returns a machine-readable `reason` so the frontend can route the user to
 * the right step instead of just showing a generic error.
 */
class ConsentRequiredMiddleware
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function __invoke(Request $request, Handler $handler): Response
    {
        $auth = $request->getAttribute('auth');
        $employeeId = (int) ($auth['sub'] ?? 0);

        $stmt = $this->db->prepare('SELECT must_change_password FROM employees WHERE id = ?');
        $stmt->execute([$employeeId]);
        $employee = $stmt->fetch();

        if (!$employee) {
            return $this->blocked('account_not_found', 'Employee account not found.');
        }

        if ((int) $employee['must_change_password'] === 1) {
            return $this->blocked('password_change_required', 'You must change your temporary password before continuing.');
        }

        if (!PolicyRepository::hasConsentedToCurrent($this->db, $employeeId)) {
            return $this->blocked('consent_required', 'You must review and accept the monitoring policy before continuing.');
        }

        return $handler->handle($request);
    }

    private function blocked(string $reason, string $message): Response
    {
        $response = new Psr7Response(403);
        $response->getBody()->write(json_encode(['error' => $message, 'reason' => $reason]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
