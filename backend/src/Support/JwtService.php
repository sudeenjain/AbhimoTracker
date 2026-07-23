<?php

namespace App\Support;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtService
{
    private string $secret;
    private int $ttl;

    public function __construct()
    {
        $this->secret = env('JWT_SECRET', '');
        $this->ttl = (int) env('JWT_TTL_SECONDS', 28800);

        if ($this->secret === '' || $this->secret === 'change-this-to-a-long-random-string') {
            // Fail loud in any environment that hasn't set a real secret,
            // rather than silently signing tokens with a known value.
            if (env('APP_ENV', 'local') !== 'local') {
                throw new \RuntimeException('JWT_SECRET must be set to a real secret outside local dev.');
            }
        }
    }

    public function issue(array $claims): string
    {
        $now = time();
        $payload = array_merge($claims, [
            'iat' => $now,
            'exp' => $now + $this->ttl,
        ]);

        return JWT::encode($payload, $this->secret, 'HS256');
    }

    /**
     * @throws \Exception if the token is invalid, expired, or malformed.
     */
    public function verify(string $token): array
    {
        $decoded = JWT::decode($token, new Key($this->secret, 'HS256'));
        return (array) $decoded;
    }
}
