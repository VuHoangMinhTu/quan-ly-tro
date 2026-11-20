<?php

namespace Tests;

use App\Custom\ApiResponse;
use App\Models\Invoice;
use App\Services\BillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

trait ExercisesLegacyBillingCalculation
{
    // Keep legacy formula coverage without reopening the production writer.
    protected function postLegacyCalculation(int $invoiceId): TestResponse
    {
        Route::middleware(['api', 'auth:sanctum'])->post('/api/__test/legacy-calculation/{id}', function (Request $request, int $id, BillingService $billing) {
            $invoice = Invoice::whereHas('room.boardingHouse', fn ($query) => $query->where('landlord_id', $request->user()->landlord?->id))->findOrFail($id);
            $billing->generateForInvoice($invoice);

            return ApiResponse::success(null, 'Tạo các khoản thu tự động thành công.');
        });

        return $this->postJson('/api/__test/legacy-calculation/'.$invoiceId);
    }
}
