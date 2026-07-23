<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as Psr7Response;

/**
 * Must run AFTER JwtAuthMiddleware. Rejects any request whose token role
 * claim isn't 'admin'.
 */
class AdminOnlyMiddleware
{
    public function __invoke(Request $request, Handler $handler): Response
    {
        $auth = $request->getAttribute('auth');

        if (!$auth || ($auth['role'] ?? null) !== 'admin') {
            $response = new Psr7Response(403);
            $response->getBody()->write(json_encode(['error' => 'Admin access required.']));
            return $response->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }
}
