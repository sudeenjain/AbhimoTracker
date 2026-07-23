<?php

namespace App\Support;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Mail sender utilizing PHPMailer when MAIL_DRIVER=smtp, otherwise logging locally.
 */
class Mailer
{
    public function sendCredentials(string $toEmail, string $name, string $username, string $tempPassword): void
    {
        $loginUrl = env('FRONTEND_BASE_URL', 'http://127.0.0.1:8090') . '/login.html';
        $subject = 'Your Abhimo Tracker login';
        $body = "Hi {$name},\n\n"
            . "You're all set. Here's how to sign in:\n\n"
            . "  Login page: {$loginUrl}\n"
            . "  Username: {$username}\n"
            . "  Temporary password: {$tempPassword}\n\n"
            . "You'll be asked to change this password the first time you log in.\n";

        $this->send($toEmail, $subject, $body);
    }

    public function sendOnboardingLink(string $toEmail, string $name, string $link): void
    {
        $subject = 'Welcome to Abhimo - activate your Abhimo Tracker account';
        $body = "Hi {$name},\n\n"
            . "Your registration has been approved. To get set up:\n\n"
            . "  {$link}\n\n"
            . "That page will let you download Abhimo Tracker and review the monitoring "
            . "policy. Once you accept it, we'll email you your login details.\n\n"
            . "This link is unique to you and expires in 7 days.\n";

        $this->send($toEmail, $subject, $body);
    }

    public function sendOTP(string $toEmail, string $name, string $otp): void
    {
        $subject = 'Your Verification OTP - Abhimo Technologies';
        $body = "Hi {$name},\n\n"
            . "Your registration verification OTP code is: {$otp}\n\n"
            . "This code is valid for 10 minutes. Please do not share it with anyone.\n";

        $this->send($toEmail, $subject, $body);
    }

    public function sendRegistrationThankYou(string $toEmail, string $name): void
    {
        $subject = 'Thank you for registration - Abhimo';
        $body = "Hi {$name},\n\n"
            . "Thank you for registration team Abhimo, we will meet you soon.\n";

        $this->send($toEmail, $subject, $body);
    }

    private function send(string $toEmail, string $subject, string $body): void
    {
        $driver = env('MAIL_DRIVER', 'log');

        if ($driver === 'log') {
            $dir = __DIR__ . '/../../storage/mail-outbox';
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $filename = $dir . '/' . date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $toEmail) . '.txt';
            file_put_contents($filename, "To: {$toEmail}\nSubject: {$subject}\n\n{$body}");
            return;
        }

        if ($driver === 'smtp') {
            $mail = new PHPMailer(true);
            try {
                // Server settings
                $mail->isSMTP();
                $mail->Host       = env('SMTP_HOST', 'smtp.gmail.com');
                $mail->SMTPAuth   = true;
                $mail->Username   = env('SMTP_USER');
                $mail->Password   = env('SMTP_PASS');
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = (int) env('SMTP_PORT', 587);
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    ]
                ];

                // Recipients
                $mail->setFrom(env('MAIL_FROM', 'noreply@example.com'), env('MAIL_FROM_NAME', 'Abhimo Technologies'));
                $mail->addAddress($toEmail);

                // Content
                $mail->isHTML(false);
                $mail->Subject = $subject;
                $mail->Body    = $body;

                $mail->send();
            } catch (Exception $e) {
                throw new \RuntimeException("Mailer Error: {$mail->ErrorInfo}");
            }
            return;
        }

        throw new \RuntimeException("MAIL_DRIVER={$driver} is not implemented yet.");
    }
}
