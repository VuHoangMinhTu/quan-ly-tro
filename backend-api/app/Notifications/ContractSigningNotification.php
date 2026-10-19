<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Synchronous: secret OTP/link must never be serialized into a queue payload. */
class ContractSigningNotification extends Notification
{
    public function __construct(public string $kind, public array $details) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if (in_array($this->kind, ['REQUEST', 'OTP'], true)) {
            return (new MailMessage)->mailer(config('signing.mailer'))
                ->subject($this->kind === 'OTP' ? 'Mã xác thực ký hợp đồng - BeeHouse' : 'Yêu cầu ký hợp đồng thuê phòng - BeeHouse')
                ->view(['html' => 'emails.contract-signing', 'text' => 'emails.contract-signing-text'], [
                    'kind' => $this->kind,
                    'details' => $this->details,
                ]);
        }

        $message = (new MailMessage)->mailer(config('signing.mailer'))->greeting('Xin chào '.$this->details['name'].',');

        return $message->subject('Cập nhật ký hợp đồng - BeeHouse')->line($this->details['message']);
    }
}
