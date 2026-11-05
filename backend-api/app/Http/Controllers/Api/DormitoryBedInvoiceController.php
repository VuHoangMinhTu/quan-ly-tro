<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Services\DormitoryBedBilling;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class DormitoryBedInvoiceController extends Controller
{
    /** Create or read the draft for ONE KTX bed contract, not the whole room. */
    public function store(Request $request, int $id, DormitoryBedBilling $billing): JsonResponse
    {
        $contract = Contract::whereHas('room.boardingHouse.landlord', fn ($query) =>
            $query->where('user_id', $request->user()->id))->findOrFail($id);
        $data = $request->validate([
            'billing_period' => ['required', 'date_format:Y-m-01'],
            'confirmation' => ['sometimes', 'array'],
            'confirmation.rent_amount' => ['sometimes', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999'],
            'confirmation.reason' => ['sometimes', 'string', 'max:1000'],
            'confirmation.extra_service_confirmed' => ['sometimes', 'boolean'],
            'confirmation.services' => ['sometimes', 'array', 'max:100'],
            'confirmation.services.*.term_id' => ['required', 'integer', 'distinct'],
            'confirmation.services.*.quantity' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999999'],
            'confirmation.services.*.amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999999'],
        ]);
        foreach ($data['confirmation']['services'] ?? [] as $term) {
            $contract->agreedServices()->findOrFail($term['term_id']);
        }
        try {
            $result = $billing->generate($contract, $data['billing_period'], $data['confirmation'] ?? [],
                $request->user()->id);
        } catch (ValidationException $error) {
            return ApiResponse::success(['status' => 'BLOCKED', 'contract_id' => $contract->id,
                'room_id' => $contract->room_id, 'errors' => $error->errors(), 'invoices' => []],
                'Chưa tạo hóa đơn cho giường này; hãy xem lý do. Các giường khác không bị ảnh hưởng.');
        }

        return ApiResponse::success($result, 'Đã kiểm tra hóa đơn riêng cho hợp đồng giường; chưa phát hành.');
    }
}
