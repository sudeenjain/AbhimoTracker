<?php

// Every timestamp comparison in this app (JWT iat/exp, attendance sign-in/out,
// onboarding token expiry, and critically ActivityController's +/-window
// check on tray-submitted timestamps) depends on PHP's notion of "now"
// agreeing with what the browser and desktop-tray app mean by "now" -- both
// of which format timestamps using the machine's LOCAL time (see
// desktop-tray/main.js's formatServerTimestamp, which uses getHours() etc,
// not getUTCHours()). Without this, PHP falls back to whatever date.timezone
// is set in php.ini -- UTC by default on a stock XAMPP install -- and every
// activity upload from a non-UTC timezone gets silently rejected as
// "out of the acceptable window" by ActivityController, since a same-instant
// local timestamp looks hours in the future/past to PHP.
//
// Set this to match wherever the server (and its employees) actually are.
date_default_timezone_set('Asia/Kolkata');

require __DIR__ . '/../config/env.php';
require __DIR__ . '/../vendor/autoload.php';

use App\Controllers\AccountController;
use App\Controllers\ActivityController;
use App\Controllers\AdminActivityController;
use App\Controllers\AdminAttendanceController;
use App\Controllers\AdminEmployeeController;
use App\Controllers\AdminEmployeeActivityController;
use App\Controllers\AdminWorkSessionController;
use App\Controllers\AttendanceController;
use App\Controllers\AuthController;
use App\Controllers\OnboardingController;
use App\Controllers\PayDecisionController;
use App\Controllers\RegistrationController;
use App\Controllers\TrayPairingController;
use App\Controllers\WebsiteActivityController;
use App\Controllers\WorkSessionController;
use App\Middleware\AdminOnlyMiddleware;
use App\Middleware\ConsentRequiredMiddleware;
use App\Middleware\JwtAuthMiddleware;
use App\Support\Cors;
use App\Support\JwtService;
use App\Support\Mailer;
use Slim\Factory\AppFactory;
use Slim\Psr7\Response as Psr7Response;

$pdo = require __DIR__ . '/../config/database.php';

$app = AppFactory::create();
$app->addBodyParsingMiddleware();

// Makes routing work whether this sits at the web root (e.g. `php -S`) or
// in a subdirectory under Apache (e.g. XAMPP htdocs/attendance-system/...).
// Without this, every route only ever matches when the app is served from
// "/" -- under a subdirectory, Slim sees the full "/attendance-system/
// backend/public/api/..." path, which none of the literal routes below
// match, and only the OPTIONS catch-all (a wildcard) does. That's the
// "Method not allowed. Must be one of: OPTIONS" error on every real route.
//
// Deliberately NOT using $_SERVER['SCRIPT_NAME'] here: on at least one
// Windows/Apache+mod_rewrite setup we saw it fail to reflect index.php's
// real path after the RewriteRule kicked in, which silently broke this
// whole detection. REQUEST_URI is what mod_rewrite is contractually
// required to leave alone on an internal rewrite (no 3xx redirect), so
// it's the reliable signal. Every real route below lives under /api, so
// "everything before /api" is the base path, on any OS/server.
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$apiPos = strpos($requestPath, '/api');
if ($apiPos !== false && $apiPos > 0) {
    $app->setBasePath(rtrim(substr($requestPath, 0, $apiPos), '/'));
}
// else: either already at root (apiPos === 0, e.g. `php -S`), or /api
// wasn't found at all (a 404 for something outside the API entirely) --
// no base path adjustment needed or possible in either case.

// CORS. See src/Support/Cors.php -- reflects an allow-listed origin from
// CORS_ALLOWED_ORIGINS, falling back to '*' only in local dev.
$app->add(function ($request, $handler) {
    $response = $handler->handle($request);
    $origin = Cors::resolve($request->getHeaderLine('Origin') ?: null);
    if ($origin !== null) {
        $response = $response->withHeader('Access-Control-Allow-Origin', $origin);
    }
    return $response
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, OPTIONS')
        // Response varies by Origin now (it's reflected, not constant) --
        // tells any intermediate cache not to serve one origin's cached
        // response to a different origin.
        ->withHeader('Vary', 'Origin');
});

$app->options('/{routes:.+}', function ($request, $response) {
    return $response;
});

$app->addErrorMiddleware((bool) env('APP_ENV', 'local') === 'local', true, true);

$jwt = new JwtService();
$mailer = new Mailer();

$jwtAuth = new JwtAuthMiddleware($jwt, $pdo);
$adminOnly = new AdminOnlyMiddleware();
$consentRequired = new ConsentRequiredMiddleware($pdo);

// ---- Public routes ----
$registrationController = new RegistrationController($pdo, $mailer);
$app->post('/api/register/send-otp', [$registrationController, 'sendOtp']);
$app->get('/api/register/check-username', [$registrationController, 'checkUsername']);
$app->post('/api/register/verify-otp', [$registrationController, 'verifyOtp']);
$app->post('/api/login', [new AuthController($pdo, $jwt), 'login']);

$onboardingController = new OnboardingController($pdo, $mailer);
$app->get('/api/onboarding/{token}', [$onboardingController, 'info']);
$app->post('/api/onboarding/{token}/activate', [$onboardingController, 'activate']);

$app->post('/api/tray/pair/{token}/exchange', [new TrayPairingController($pdo), 'exchange']);
// Same exchange logic as the tray -- a pairing token is single-use and
// already tied to one employee, so there's nothing client-specific about
// redeeming it. Kept as its own route (rather than reusing the tray URL
// from the extension) purely so logs/docs read clearly about which client
// paired when.
$app->post('/api/extension/pair/{token}/exchange', [new TrayPairingController($pdo), 'exchange']);

$app->get('/api/health', function ($request, $response) {
    $response->getBody()->write(json_encode(['status' => 'ok']));
    return $response->withHeader('Content-Type', 'application/json');
});

// ---- Authenticated routes needed to CLEAR the consent gate (Phase 2) ----
// These deliberately do NOT go through ConsentRequiredMiddleware -- they are
// the mechanism by which an employee satisfies it.
$app->group('/api', function ($group) use ($pdo) {
    $account = new AccountController($pdo);
    $group->post('/account/change-password', [$account, 'changePassword']);
    $group->get('/policy/current', [$account, 'currentPolicy']);
    $group->post('/consent/accept', [$account, 'acceptConsent']);
    // Phase 13: deliberately NOT behind ConsentRequiredMiddleware, same
    // reasoning as accept/current above -- withdrawing consent has to be
    // reachable precisely when consent is (or was) the only thing gating
    // the rest of the app, not locked behind the gate it's meant to lift.
    $group->post('/consent/withdraw', [$account, 'withdrawConsent']);
})->add($jwtAuth);

// ---- Employee attendance routes (Phase 3) ----
// Gated by ConsentRequiredMiddleware: password already changed AND consent
// recorded for the current monitoring_policy version, on top of a valid JWT.
$app->group('/api/attendance', function ($group) use ($pdo) {
    $attendance = new AttendanceController($pdo);
    $group->post('/sign-in', [$attendance, 'signIn']);
    $group->post('/sign-out', [$attendance, 'signOut']);
    $group->get('/today', [$attendance, 'today']);
})->add($consentRequired)->add($jwtAuth);

// ---- Employee browser work-session tracking ----
// Same gate as attendance: valid JWT, password already changed, current
// monitoring policy consented to. Heartbeats come from the signed-in
// employee's own browser tab, not a separate agent.
$app->group('/api/work-session', function ($group) use ($pdo) {
    $workSession = new WorkSessionController($pdo);
    $group->post('/heartbeat', [$workSession, 'heartbeat']);
    $group->post('/progress', [$workSession, 'updateProgress']);
    $group->get('/today', [$workSession, 'today']);
})->add($consentRequired)->add($jwtAuth);

// ---- Employee activity ingest (Phase 4 + Phase 6) ----
// Same gate as attendance: valid JWT, password already changed, current
// monitoring policy consented to. The desktop tracking agent authenticates
// as the employee and posts to /ingest; the browser extension (Phase 6)
// posts domain-only usage to /website using the identical gate/auth model.
$app->group('/api/activity', function ($group) use ($pdo) {
    $activity = new ActivityController($pdo);
    $group->post('/ingest', [$activity, 'ingest']);

    $websiteActivity = new WebsiteActivityController($pdo);
    $group->post('/website', [$websiteActivity, 'ingest']);
})->add($consentRequired)->add($jwtAuth);

// ---- Admin-only routes (Phase 1 & 3) ----
$app->group('/api/admin', function ($group) use ($pdo, $mailer) {
    $adminEmployees = new AdminEmployeeController($pdo, $mailer);
    $group->get('/employees', [$adminEmployees, 'list']);
    $group->post('/employees/{id}/approve', [$adminEmployees, 'approve']);
    $group->post('/employees/{id}/reject', [$adminEmployees, 'reject']);

    $group->post('/employees/{id}/revoke-sessions', [$adminEmployees, 'revokeSessions']);

    $adminAttendance = new AdminAttendanceController($pdo);
    $group->get('/attendance', [$adminAttendance, 'forDate']);

    // ---- Phase 4 + Phase 6 ----
    $adminActivity = new AdminActivityController($pdo);
    $group->get('/live-status', [$adminActivity, 'liveStatus']);
    $group->get('/activity/app-usage', [$adminActivity, 'appUsage']);
    $group->get('/activity/website-usage', [$adminActivity, 'websiteUsage']);
    $group->get('/daily-summary', [$adminActivity, 'forDate']);

    // ---- Phase 10: single employee's full activity page (attendance,
    // app usage, website usage, timeline) for a given date ----
    $adminEmployeeActivity = new AdminEmployeeActivityController($pdo);
    $group->get('/employees/{id}/activity', [$adminEmployeeActivity, 'detail']);
    // ---- Phase 12: Today / Yesterday / Last 7 Days / Custom Date ----
    $group->get('/employees/{id}/activity/range', [$adminEmployeeActivity, 'range']);

    // ---- Browser work-session live status / daily summary ----
    $adminWorkSessions = new AdminWorkSessionController($pdo);
    $group->get('/work-sessions/live', [$adminWorkSessions, 'liveStatus']);
    $group->get('/work-sessions/summary', [$adminWorkSessions, 'summary']);

    $payDecisions = new PayDecisionController($pdo);
    $group->post('/pay-decisions', [$payDecisions, 'create']);
    $group->get('/pay-decisions', [$payDecisions, 'list']);
})->add($adminOnly)->add($jwtAuth);

$app->run();
