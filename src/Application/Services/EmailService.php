<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Models\User;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class EmailService
{
    public function sendPasswordReset(User $user, string $token): bool
    {
        $url = rtrim((string) env('APP_URL', 'http://localhost:8080'), '/')
            . '/reset-password?token=' . rawurlencode($token);

        return $this->send(
            $user,
            'Reset your password',
            "Use the following link to reset your password:\n\n{$url}\n\n"
                . "This link expires in 60 minutes. If you did not request it, ignore this email.",
        );
    }

    public function sendEmailVerification(User $user, string $token): bool
    {
        $url = rtrim((string) env('APP_URL', 'http://localhost:8080'), '/')
            . '/verify-email?token=' . rawurlencode($token);

        return $this->send(
            $user,
            'Verify your email address',
            "Use the following link to verify your email address:\n\n{$url}\n\n"
                . 'This link expires in 24 hours.',
        );
    }

    private function send(User $user, string $subject, string $body): bool
    {
        $dsn = trim((string) env('MAILER_DSN', ''));
        if ($dsn === '') {
            return false;
        }

        try {
            $mailer = new Mailer(Transport::fromDsn($dsn));
            $email = (new Email())
                ->from(new Address(
                    (string) env('MAIL_FROM_ADDRESS', 'noreply@example.com'),
                    (string) env('MAIL_FROM_NAME', 'Slim PHP API'),
                ))
                ->to(new Address($user->email, trim($user->first_name . ' ' . $user->last_name)))
                ->subject($subject)
                ->text($body);

            $mailer->send($email);
            return true;
        } catch (\Throwable $exception) {
            return false;
        }
    }
}
