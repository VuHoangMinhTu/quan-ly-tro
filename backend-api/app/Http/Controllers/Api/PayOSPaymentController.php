<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\LandlordPayOSConfig;
use App\Services\PayOSService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PayOS\Exceptions\WebhookException;

class PayOSPaymentController extends Controller
{
    public function store(Request $request, int $invoiceId, PayOSService $payos): JsonResponse
    {
        $invoice = Invoice::query()
            ->whereHas('room.boardingHouse', fn ($query) => $query->where('landlord_id', $request->user()->landlord->id))
            ->findOrFail($invoiceId);

        $paymentRequest = $payos->createOrReuse($invoice);

        return ApiResponse::success([
            'payment_request' => $payos->payload($paymentRequest),
        ], $payos->messageFor($paymentRequest));
    }

    public function webhook(Request $request, PayOSService $payos): JsonResponse
    {
        $fallbackId = (int) config('services.payos.global_fallback_landlord_id', 0);
        abort_unless($fallbackId > 0, 404);
        try {
            $data = $payos->verifyWebhook($request->all());
        } catch (WebhookException $exception) {
            Log::warning('Rejected invalid payOS webhook signature.', ['exception' => $exception->getMessage()]);

            return ApiResponse::error('Dữ liệu webhook payOS không hợp lệ.', null, 400);
        }

        $payos->processVerifiedWebhook($data, $fallbackId);

        return ApiResponse::success(null, 'Xử lý webhook payOS thành công.');
    }

    public function landlordWebhook(Request $request, string $webhookUuid, PayOSService $payos): JsonResponse
    {
        // Disconnected merchants can still deliver a payment for an earlier QR.
        $config = LandlordPayOSConfig::query()->where('webhook_uuid', $webhookUuid)->firstOrFail();
        try {
            $data = $payos->verifyWebhook($request->all(), $config);
        } catch (WebhookException) {
            return ApiResponse::error('Dữ liệu webhook payOS không hợp lệ.', null, 400);
        }
        $payos->processVerifiedWebhook($data, (int) $config->landlord_id, (int) $config->id);

        return ApiResponse::success(null, 'Xử lý webhook payOS thành công.');
    }
}
