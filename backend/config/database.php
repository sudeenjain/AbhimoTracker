<?php

/**
 * Returns a shared PDO connection using settings from .env.
 * Every DB access in this project goes through this file -- no direct
 * mysqli/PDO instantiation anywhere else, so connection config stays in one place.
 */

$host = env('DB_HOST', '127.0.0.1');
$port = env('DB_PORT', '3306');
$name = env('DB_NAME', 'attendance_system');
$user = env('DB_USER', 'app_user');
$pass = env('DB_PASS', '');

$dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (\PDOException $e) {
    error_log("Database connection failed: {$e->getMessage()}");

    // bin/migrate.php and bin/aggregate-daily-summary.php require this
    // file directly without loading vendor/autoload.php first (they have
    // no need for the rest of the app's classes) -- CORS headers are
    // meaningless for a CLI script anyway, and referencing
    // App\Support\Cors here unconditionally would fatal with "class not
    // found" instead of the intended error message in that context.
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
        header('Content-Type: application/json');
        // Same allow-list as the main app's CORS middleware (see
        // src/Support/Cors.php) -- this path runs before Slim boots, so
        // it's duplicated here rather than shared through the PSR-7
        // request Slim would otherwise provide.
        if (class_exists(\App\Support\Cors::class)) {
            $origin = \App\Support\Cors::resolve($_SERVER['HTTP_ORIGIN'] ?? null);
            if ($origin !== null) {
                header("Access-Control-Allow-Origin: {$origin}");
            }
        }
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
        echo json_encode(['error' => 'Database connection failed. Is MySQL running and are backend/.env credentials correct?']);
    } else {
        fwrite(STDERR, "Database connection failed: {$e->getMessage()}\n");
    }
    exit(1);
}

return $pdo;
