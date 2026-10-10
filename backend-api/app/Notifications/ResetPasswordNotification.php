<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    public function __construct(
        public readonly string $resetUrl,
        public readonly int $expiresInMinutes,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = trim((string) config('app.name'));
        $appName = $appName === '' || $appName === 'Laravel' ? 'BeeHouse - Quản lý trọ' : $appName;

        return (new MailMessage)
            ->subject('Đặt lại mật khẩu BeeHouse')
            ->view([
                'html' => 'emails.reset-password',
                'text' => 'emails.reset-password-text',
            ], [
                'appName' => $appName,
                'resetUrl' => $this->resetUrl,
                'expiresInMinutes' => $this->expiresInMinutes,
            ]);
    }
}
