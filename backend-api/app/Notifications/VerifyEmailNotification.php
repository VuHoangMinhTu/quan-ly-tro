<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

class VerifyEmailNotification extends VerifyEmail
{
    /**
     * Customize presentation only; the parent still generates the signed URL.
     */
    protected function buildMailMessage(mixed $url): MailMessage
    {
        $appName = trim((string) config('app.name'));
        $appName = $appName === '' || $appName === 'Laravel' ? 'Quản lý nhà trọ' : $appName;

        return (new MailMessage)
            ->subject('Xác minh địa chỉ email của bạn')
            ->action('Xác minh email', $url)
            ->view([
                'html' => 'emails.verify-email',
                'text' => 'emails.verify-email-text',
            ], [
                'appName' => $appName,
                'verificationUrl' => $url,
                'expiresInMinutes' => (int) config('auth.verification.expire', 60),
            ]);
    }
}
