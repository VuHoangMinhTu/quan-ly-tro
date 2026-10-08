<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
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
        try {
            $data = $payos->verifyWebhook($request->all());
        } catch (WebhookException $exception) {
            Log::warning('Rejected invalid payOS webhook signature.', ['exception' => $exception->getMessage()]);

            return ApiResponse::error('Invalid payOS webhook.', null, 400);
        }

        $payos->processVerifiedWebhook($data);

        return ApiResponse::success(null, 'payOS webhook processed.');
    }
}
