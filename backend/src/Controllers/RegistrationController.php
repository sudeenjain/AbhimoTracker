<?php

namespace App\Controllers;

use App\Support\Json;
use App\Support\Mailer;
use App\Support\RateLimiter;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class RegistrationController
{
    // Shared between checkUsername() (live, as-you-type) and verifyOtp()
    // (final, on submit) so the two can never silently disagree about what
    // counts as a valid username.
    private const USERNAME_RE = '/^[a-z][a-z0-9_.]{2,29}$/';

    /** Phase 14: caps how many verification emails one address can trigger
     *  per hour -- send-otp is public and unauthenticated, so without this
     *  it's a free way to spam an inbox or slowly probe which emails are
     *  already registered via the 409 response above. */
    private const OTP_MAX_ATTEMPTS = 5;
    private const OTP_WINDOW_SECONDS = 3600; // 1 hour

    private PDO $db;
    private Mailer $mailer;

    public function __construct(PDO $db, Mailer $mailer)
    {
        $this->db = $db;
        $this->mailer = $mailer;
    }

    /**
     * POST /api/register/send-otp
     * Public. Generates OTP, stores it, and sends it to the user's email.
     */
    public function sendOtp(Request $request, Response $response): Response
    {
        $data = Json::body($request);

        $name = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));

        if ($name === '') {
            return Json::write($response, ['errors' => ['Name is required']], 422);
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Json::write($response, ['errors' => ['A valid email is required']], 422);
        }

        // Check if employee email already exists
        $stmt = $this->db->prepare('SELECT id FROM employees WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            return Json::write($response, ['errors' => ['An application with this email already exists']], 409);
        }

        if (RateLimiter::tooMany($this->db, 'send_otp', strtolower($email), self::OTP_MAX_ATTEMPTS, self::OTP_WINDOW_SECONDS)) {
            return Json::write($response, [
                'errors' => ['Too many verification codes requested for this email. Please try again later.'],
            ], 429);
        }
        RateLimiter::hit($this->db, 'send_otp', strtolower($email));

        // Generate 6-digit code
        $otp = sprintf("%06d", mt_rand(100000, 999999));
        $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minutes

        // Insert or replace OTP record
        $stmt = $this->db->prepare(
            'INSERT INTO otp_verifications (email, otp, expires_at)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE otp = VALUES(otp), expires_at = VALUES(expires_at), created_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([$email, $otp, $expiresAt]);

        try {
            $this->mailer->sendOTP($email, $name, $otp);
        } catch (\Exception $e) {
            return Json::write($response, ['errors' => ['Failed to send email: ' . $e->getMessage()]], 500);
        }

        return Json::write($response, [
            'message' => 'Verification code sent to your email.'
        ], 200);
    }

    /**
     * GET /api/register/check-username?username=...
     * Public. Lets the registration form check availability as the person
     * types, instead of only finding out at final submit after they've
     * already gone through email + OTP. Same format rule and uniqueness
     * check as verifyOtp() -- kept in sync deliberately, see USERNAME_RE.
     */
    public function checkUsername(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $username = strtolower(trim((string) ($params['username'] ?? '')));

        if ($username === '') {
            return Json::write($response, ['available' => false, 'error' => 'Username is required.'], 200);
        }
        if (!preg_match(self::USERNAME_RE, $username)) {
            return Json::write($response, [
                'available' => false,
                'error' => 'Start with a letter; 3-30 characters (letters, numbers, dots, underscores only).',
            ], 200);
        }

        $stmt = $this->db->prepare('SELECT id FROM employees WHERE username = ?');
        $stmt->execute([$username]);

        if ($stmt->fetch()) {
            return Json::write($response, ['available' => false, 'error' => 'That username is already taken.'], 200);
        }

        return Json::write($response, ['available' => true], 200);
    }

    /**
     * POST /api/register/verify-otp
     * Public. Verifies the OTP, registers employee with status 'pending' if correct,
     * and sends a thank you email.
     */
    public function verifyOtp(Request $request, Response $response): Response
    {
        $data = Json::body($request);

        $name = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $role = trim((string) ($data['role'] ?? ''));
        $availability = trim((string) ($data['availability_preference'] ?? ''));
        $otpInput = trim((string) ($data['otp'] ?? ''));
        // Lowercased so it matches the case-insensitive collation on
        // employees.username (utf8mb4_unicode_ci) and stays consistent with
        // the auto-generated fallback style (see PasswordGenerator::usernameFromName).
        $username = strtolower(trim((string) ($data['username'] ?? '')));

        $errors = [];
        if ($name === '') $errors[] = 'name is required';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'a valid email is required';
        if ($phone === '') $errors[] = 'phone is required';
        if ($role === '') $errors[] = 'role is required';
        if ($otpInput === '') $errors[] = 'verification OTP code is required';
        if ($username === '') {
            $errors[] = 'username is required';
        } elseif (!preg_match(self::USERNAME_RE, $username)) {
            $errors[] = 'username must start with a letter and be 3-30 characters (letters, numbers, dots, underscores only)';
        }

        if ($errors) {
            return Json::write($response, ['errors' => $errors], 422);
        }

        // Check if employee email already exists
        $stmt = $this->db->prepare('SELECT id FROM employees WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            return Json::write($response, ['errors' => ['an application with this email already exists']], 409);
        }

        // Username is reserved from registration onward (not generated later
        // at onboarding, see OnboardingController::activate), so it needs its
        // own first-come-first-served uniqueness check here, same as email.
        $stmt = $this->db->prepare('SELECT id FROM employees WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            return Json::write($response, ['errors' => ['that username is already taken -- please choose another']], 409);
        }

        // Verify OTP from database
        $stmt = $this->db->prepare('SELECT * FROM otp_verifications WHERE email = ?');
        $stmt->execute([$email]);
        $record = $stmt->fetch();

        if (!$record || $record['otp'] !== $otpInput) {
            return Json::write($response, ['errors' => ['Invalid verification code']], 422);
        }

        if (strtotime($record['expires_at']) < time()) {
            return Json::write($response, ['errors' => ['Verification code has expired']], 422);
        }

        // Insert into employees table
        $stmt = $this->db->prepare(
            'INSERT INTO employees (name, email, phone, role, availability_preference, username, status)
             VALUES (?, ?, ?, ?, ?, ?, \'pending\')'
        );
        try {
            $stmt->execute([$name, $email, $phone, $role, $availability ?: null, $username]);
        } catch (\PDOException $e) {
            // Race condition: someone else grabbed this email/username between
            // our SELECT checks above and this INSERT. The UNIQUE KEYs on
            // employees.email and employees.username are the real backstop;
            // this just turns a raw constraint-violation error (SQLSTATE
            // 23000) into a message the frontend can show.
            if ((int) $e->getCode() === 23000) {
                return Json::write($response, ['errors' => ['that email or username was just taken -- please try again']], 409);
            }
            throw $e;
        }

        $employeeId = (int) $this->db->lastInsertId();

        // Safe delete verification record
        $del = $this->db->prepare('DELETE FROM otp_verifications WHERE email = ?');
        $del->execute([$email]);

        // Send confirmation email
        try {
            $this->mailer->sendRegistrationThankYou($email, $name);
        } catch (\Exception $e) {
            // Log it but do not block registration return since the insert was successful
            error_log("Failed to send thank you registration email to {$email}: " . $e->getMessage());
        }

        // Notify RelayHub of new registration
        try {
            $relayHubUrl = env('RELAY_HUB_URL', 'http://127.0.0.1:5080');
            $jwtSecret = env('JWT_SECRET');
            
            $ch = curl_init("{$relayHubUrl}/api/relay/new-registration");
            $payload = json_encode([
                'id' => $employeeId,
                'name' => $name,
                'email' => $email,
                'phone' => $phone
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                "X-Relay-Secret: {$jwtSecret}"
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Exception $e) {
            error_log("Failed to notify RelayHub of new registration: " . $e->getMessage());
        }

        return Json::write($response, [
            'message' => 'Thank you for registration team Abhimo, we will meet you soon',
            'employee_id' => $employeeId,
            'status' => 'pending',
        ], 201);
    }
}
