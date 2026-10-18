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
        $message = (new MailMessage)->mailer(config('signing.mailer'))->greeting('Xin chào '.$this->details['name'].',');
        if ($this->kind === 'OTP') {
            return $message->subject('Mã xác thực ký hợp đồng - BeeHouse')
                ->line('Mã xác thực của bạn: '.$this->details['otp'])
                ->line('Mã có hiệu lực '.$this->details['minutes'].' phút. Không chia sẻ mã này với người khác.')
                ->line('Nếu không yêu cầu ký hợp đồng, vui lòng bỏ qua email này.');
        }
        if ($this->kind === 'REQUEST') {
            return $message->subject('Yêu cầu ký hợp đồng thuê phòng - BeeHouse')
                ->line('Hợp đồng: '.$this->details['code'])
                ->line('Phòng '.$this->details['room'].' — '.$this->details['house'])
                ->action('Xem và ký hợp đồng', $this->details['url'])
                ->line('Liên kết hết hạn lúc '.$this->details['expires'].'. Vui lòng không chia sẻ liên kết.')
                ->line('Đây là yêu cầu xác nhận ký điện tử bằng OTP và chữ ký vẽ tay.');
        }

        return $message->subject('Cập nhật ký hợp đồng - BeeHouse')->line($this->details['message']);
    }
}
