<?php

namespace App\Support;

class PasswordGenerator
{
    /**
     * Generates a random temp password that satisfies a basic complexity
     * rule (upper, lower, digit, symbol) so it isn't rejected by the
     * password-change form's own validation on first login.
     */
    public static function generateTemp(int $length = 12): string
    {
        $upper  = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower  = 'abcdefghijkmnpqrstuvwxyz';
        $digits = '23456789';
        $symbols = '!@#$%&*';
        $all = $upper . $lower . $digits . $symbols;

        $password = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $symbols[random_int(0, strlen($symbols) - 1)],
        ];

        for ($i = count($password); $i < $length; $i++) {
            $password[] = $all[random_int(0, strlen($all) - 1)];
        }

        shuffle($password);

        return implode('', $password);
    }

    /**
     * Generates a unique-ish username from a full name, e.g. "Jane Doe" -> "jane.doe".
     * Caller is responsible for resolving collisions (see AdminEmployeeController).
     */
    public static function usernameFromName(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '.', $slug);
        return trim($slug, '.');
    }

    /**
     * Same slugging as usernameFromName, but resolves collisions against the
     * employees table itself (appending 2, 3, ... until free). Shared by
     * AdminEmployeeController::approve() and OnboardingController::activate
     * -- credentials can now be created from either place, but the username
     * needs to be unique regardless of which path created them.
     */
    public static function uniqueUsername(\PDO $db, string $name, int $fallbackId): string
    {
        $base = self::usernameFromName($name);
        if ($base === '') {
            $base = 'employee' . $fallbackId;
        }

        $candidate = $base;
        $suffix = 1;

        $check = $db->prepare('SELECT id FROM employees WHERE username = ?');
        while (true) {
            $check->execute([$candidate]);
            if (!$check->fetch()) {
                return $candidate;
            }
            $suffix++;
            $candidate = $base . $suffix;
        }
    }
}
