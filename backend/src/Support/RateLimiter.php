<?php

namespace App\Support;

use PDO;

/**
 * Phase 14. Minimal sliding-window rate limiter backed by
 * request_throttle_log (migration 019). Deliberately DB-backed rather than
 * in-memory/APCu/Redis -- this app already assumes nothing but PHP + MySQL
 * (see PHASES.md / composer.json), and login/OTP volume is far too low for
 * a row-count query per attempt to matter.
 *
 * Usage (see AuthController::login for the full pattern):
 *   if (RateLimiter::tooMany($db, 'login', $identifier, 8, 900)) { ...429... }
 *   ... on failure only ...
 *   RateLimiter::hit($db, 'login', $identifier);
 */
class RateLimiter
{
    /**
     * @return bool true if $identifier already has >= $maxAttempts hits in
     *   the last $windowSeconds for this $bucket.
     */
    public static function tooMany(PDO $db, string $bucket, string $identifier, int $maxAttempts, int $windowSeconds): bool
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM request_throttle_log
             WHERE bucket = ? AND identifier = ? AND created_at >= (NOW() - INTERVAL ? SECOND)'
        );
        $stmt->execute([$bucket, $identifier, $windowSeconds]);
        return ((int) $stmt->fetchColumn()) >= $maxAttempts;
    }

    /**
     * Records one hit and opportunistically prunes rows for this bucket
     * older than 2x the caller's typical window, so the table never grows
     * unbounded without needing a separate cron job. $pruneOlderThanSeconds
     * defaults generously (24h) since callers pass their own window to
     * tooMany() independently -- this is just housekeeping, not the actual
     * throttle logic.
     */
    public static function hit(PDO $db, string $bucket, string $identifier, int $pruneOlderThanSeconds = 86400): void
    {
        $insert = $db->prepare(
            'INSERT INTO request_throttle_log (bucket, identifier, created_at) VALUES (?, ?, NOW())'
        );
        $insert->execute([$bucket, $identifier]);

        // Cheap, occasional prune -- only 1-in-20 hits actually runs the
        // DELETE, so this doesn't add a second query to every request.
        if (random_int(1, 20) === 1) {
            $prune = $db->prepare(
                'DELETE FROM request_throttle_log WHERE bucket = ? AND created_at < (NOW() - INTERVAL ? SECOND)'
            );
            $prune->execute([$bucket, $pruneOlderThanSeconds]);
        }
    }
}
