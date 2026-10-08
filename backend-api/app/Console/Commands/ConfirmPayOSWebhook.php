<?php

namespace App\Console\Commands;

use App\Services\PayOSService;
use Illuminate\Console\Command;
use PayOS\Exceptions\APIException;

class ConfirmPayOSWebhook extends Command
{
    protected $signature = 'payos:confirm-webhook';

    protected $description = 'Confirm the configured payOS webhook URL with payOS';

    public function handle(PayOSService $payos): int
    {
        $url = config('services.payos.webhook_url');
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            $this->error('PAYOS_WEBHOOK_URL must be a non-empty valid URL.');

            return self::FAILURE;
        }

        $this->line('Confirming payOS webhook: '.$url);

        try {
            $response = $payos->confirmWebhook();
            $confirmedUrl = is_array($response) ? ($response['webhookUrl'] ?? $url) : $response->webhookUrl;

            $this->info('payOS webhook confirmed successfully.');
            $this->line('Webhook URL: '.$confirmedUrl);

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('payOS webhook confirmation failed.');
            $this->reportPayOSError($exception);

            return self::FAILURE;
        }
    }

    private function reportPayOSError(\Throwable $exception): void
    {
        $message = $exception->getMessage();
        $status = $exception instanceof APIException ? $exception->status : null;
        $code = $exception instanceof APIException ? $exception->errorCode : null;

        // SDK v2 wraps API exceptions in WebhookException but preserves their text.
        preg_match('/HTTP\s+(\d+)/', $message, $statusMatch);
        preg_match('/code:\s*([^)]+)/i', $message, $codeMatch);

        $this->line('HTTP status: '.($status ?? ($statusMatch[1] ?? 'unavailable')));
        $this->line('payOS error code: '.($code ?? ($codeMatch[1] ?? 'unavailable')));
        $this->line('Message: '.$message);
    }
}
