<?php

namespace App\Support;

use PDO;

class PolicyRepository
{
    /**
     * "Current" policy = highest version number. Used both to show the
     * employee what they're consenting to, and to check whether an
     * existing consent record is still up to date.
     */
    public static function current(PDO $db): ?array
    {
        $stmt = $db->query('SELECT id, version, content, effective_date FROM monitoring_policy ORDER BY version DESC LIMIT 1');
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function hasConsentedToCurrent(PDO $db, int $employeeId): bool
    {
        $current = self::current($db);
        if (!$current) {
            return false;
        }

        // withdrawn_at IS NULL (migration 018) -- a withdrawn acceptance no
        // longer counts as active consent, even though the row itself is
        // kept for the historical record (see AccountController::withdrawConsent).
        $stmt = $db->prepare(
            'SELECT id FROM consent_records WHERE employee_id = ? AND policy_version = ? AND withdrawn_at IS NULL'
        );
        $stmt->execute([$employeeId, $current['version']]);
        return (bool) $stmt->fetch();
    }
}
