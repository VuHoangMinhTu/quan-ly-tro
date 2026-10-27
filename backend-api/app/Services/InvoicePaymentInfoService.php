<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\LandlordPaymentProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoicePaymentInfoService
{
    public const FIELDS = ['bank_name', 'bank_account_name', 'bank_account_number', 'payment_qr_path'];

    public function capture(Invoice $invoice): void
    {
        if ($invoice->getOriginal('payment_snapshot') !== null) {
            $invoice->payment_snapshot = $invoice->getOriginal('payment_snapshot');

            return;
        }
        if ($invoice->status === 'DRAFT' && $invoice->issued_at === null) {
            return;
        }
        if ($invoice->exists && ($invoice->getOriginal('status') !== 'DRAFT' || $invoice->getOriginal('issued_at') !== null)) {
            $invoice->payment_snapshot = null;

            return;
        }
        // Profile edits take the same landlord lock; snapshot capture and issue
        // occur in the invoice save transaction, never from a GET request.
        DB::table('landlords')->where('id', $invoice->landlord_id)->lockForUpdate()->firstOrFail();
        if ($invoice->exists) {
            $saved = DB::table('invoices')->where('id', $invoice->id)->lockForUpdate()->value('payment_snapshot');
            if ($saved !== null) {
                $invoice->payment_snapshot = json_decode($saved, true, 512, JSON_THROW_ON_ERROR);

                return;
            }
        }
        $invoice->payment_snapshot = $this->current($invoice->landlord_id);
    }

    private function current(int $landlordId): array
    {
        return [...array_fill_keys(self::FIELDS, null), ...LandlordPaymentProfile::where('landlord_id', $landlordId)->first()?->only(self::FIELDS) ?? []];
    }

    public function profile(?LandlordPaymentProfile $profile): array
    {
        return $this->present($profile?->only(self::FIELDS) ?? [], '/landlord/payment-profile/qr');
    }

    private function stored(Invoice $invoice): array
    {
        return $invoice->status === 'DRAFT' && $invoice->issued_at === null
            ? $this->current($invoice->landlord_id)
            : ($invoice->payment_snapshot ?? []);
    }

    public function forInvoice(Invoice $invoice): array
    {
        $info = $this->stored($invoice);
        $draft = $invoice->status === 'DRAFT' && $invoice->issued_at === null;
        $missingSnapshot = ! $draft && ! $this->configured($info);

        return [...$this->present($info, "/invoices/{$invoice->id}/payment-qr"),
            'source' => $draft ? 'current_profile' : ($missingSnapshot ? 'missing_snapshot' : 'snapshot'),
            'current_profile_configured' => $missingSnapshot ? $this->configured($this->current($invoice->landlord_id)) : null];
    }

    private function configured(array $info): bool
    {
        foreach (self::FIELDS as $field) {
            if (trim((string) ($info[$field] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function present(array $info, string $endpoint): array
    {
        $hasQrPath = ! empty($info['payment_qr_path']);
        $hasQr = $hasQrPath && Storage::disk('local')->exists($info['payment_qr_path']);

        return ['bank_name' => $info['bank_name'] ?? null, 'bank_account_name' => $info['bank_account_name'] ?? null,
            'bank_account_number' => $info['bank_account_number'] ?? null, 'has_payment_qr' => $hasQr,
            'payment_qr_missing' => $hasQrPath && ! $hasQr,
            'payment_qr_version' => $hasQr ? hash('sha256', $info['payment_qr_path']) : null,
            'payment_qr_url' => $hasQr ? $endpoint : null];
    }

    public function invoiceQr(Invoice $invoice): StreamedResponse
    {
        return $this->image($this->stored($invoice)['payment_qr_path'] ?? null, $invoice->landlord_id);
    }

    public function image(?string $path, int $landlordId): StreamedResponse
    {
        // No filesystem paths are accepted from the client; enforce the owner
        // directory again before serving any stored reference.
        abort_unless($path && preg_match('#^payment-qrs/'.preg_quote((string) $landlordId, '#').'/[a-zA-Z0-9]+\.(png|jpe?g|webp)$#', $path), 404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
