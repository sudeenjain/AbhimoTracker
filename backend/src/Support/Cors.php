<?php

namespace App\Support;

/**
 * Phase 14. Resolves which Origin (if any) a request is allowed to see in
 * Access-Control-Allow-Origin. Reflects back the specific requesting
 * origin when it's on the CORS_ALLOWED_ORIGINS allow-list, instead of the
 * blanket '*' this app used to send on every response. This API is only
 * ever called from the admin dashboard, the employee-facing pages, the
 * desktop tray, and the browser extension -- all known, fixed origins --
 * so there's no real need for '*', and it's the kind of thing worth
 * tightening before a production rollout even though Bearer-token auth
 * (not cookies) already keeps the practical risk low.
 *
 * Falls back to '*' ONLY when APP_ENV=local and CORS_ALLOWED_ORIGINS is
 * unset, so local development (opening frontend/*.html directly, or via
 * any random local dev server port) keeps working with zero config --
 * same "fail loud outside local, stay convenient inside it" convention
 * JwtService already uses for JWT_SECRET.
 */
class Cors
{
    /** @return string[] */
    private static function allowedOrigins(): array
    {
        $raw = (string) env('CORS_ALLOWED_ORIGINS', '');
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * @param string|null $requestOrigin The request's Origin header, or
     *   null if the request didn't send one (e.g. a same-origin request,
     *   or a non-browser client like the desktop tray's Node fetch).
     * @return string|null The value to send as Access-Control-Allow-Origin,
     *   or null to omit the header entirely -- browsers block the
     *   cross-origin response in that case, which is the correct behavior
     *   for an origin that isn't on the allow-list.
     */
    public static function resolve(?string $requestOrigin): ?string
    {
        $allowed = self::allowedOrigins();

        if ($allowed === []) {
            // Nothing configured -- permissive only in local dev; outside
            // local this is a misconfiguration to fix, not a green light
            // for '*'.
            return env('APP_ENV', 'local') === 'local' ? '*' : null;
        }

        if ($requestOrigin !== null && in_array($requestOrigin, $allowed, true)) {
            return $requestOrigin;
        }

        return null;
    }
}
