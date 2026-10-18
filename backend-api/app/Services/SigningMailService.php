<?php

namespace App\Services;

use App\Notifications\ContractSigningNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class SigningMailService
{
    private function safeMailer(string $name, array $visited = []): bool
    {
        if (in_array($name, $visited, true)) {
            return false;
        }
        $config = config('mail.mailers.'.$name, []);
        if (in_array($config['transport'] ?? '', ['failover', 'roundrobin'], true)) {
            return ! empty($config['mailers']) && collect($config['mailers'])->every(fn ($child) => $this->safeMailer($child, [...$visited, $name]));
        }

        return in_array($config['transport'] ?? '', ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun'], true)
            || (app()->runningUnitTests() && ($config['transport'] ?? '') === 'array');
    }

    public function send(string $email, string $kind, array $details): void
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! $this->safeMailer((string) config('signing.mailer'))) {
            throw ValidationException::withMessages(['email' => 'Chưa cấu hình kênh email ký hợp đồng an toàn. Không sử dụng mailer ghi log cho OTP hoặc liên kết ký.']);
        }
        try {
            Notification::route('mail', $email)->notify(new ContractSigningNotification($kind, $details));
        } catch (\Throwable) {
            // Mail transport diagnostics may contain the secret message body.
            throw ValidationException::withMessages(['email' => 'Chưa thể gửi email ký hợp đồng. Vui lòng thử lại hoặc liên hệ quản trị viên.']);
        }
    }
}
