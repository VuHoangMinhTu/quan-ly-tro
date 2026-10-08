<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Exceptions\InvoiceFinancialEditException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invoice\StoreInvoiceRequest;
use App\Http\Requests\Invoice\UpdateInvoiceRequest;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Room;
use App\Services\BillingService;
use App\Services\PayOSService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    public function index(Request $r, int $roomId): JsonResponse
    {
        return ApiResponse::success($this->room($r, $roomId)->invoices()->with('contract')->orderByDesc('billing_period')->get(), 'Invoices retrieved successfully.');
    }

    public function store(StoreInvoiceRequest $r, int $roomId): JsonResponse
    {
        $room = $this->room($r, $roomId);
        $d = $r->validated();
        $this->contract($room, $d['contract_id'] ?? null);
        $d['billing_period'] = date('Y-m-01', strtotime($d['billing_period']));
        $this->period($room, $d['billing_period']);
        $room->invoices()->create([...$d, 'subtotal' => 0, 'total_amount' => 0, 'paid_amount' => 0]);

        return ApiResponse::success(null, 'Invoice created successfully.', 201);
    }

    public function show(Request $r, int $id): JsonResponse
    {
        return ApiResponse::success($this->invoice($r, $id)->load(['room.boardingHouse', 'contract.tenant', 'items', 'payosPaymentRequest']), 'Invoice retrieved successfully.');
    }

    public function update(UpdateInvoiceRequest $r, int $id): JsonResponse
    {
        $i = $this->invoice($r, $id);
        $d = $r->validated();
        $d['billing_period'] = date('Y-m-01', strtotime($d['billing_period']));

        if ($this->changesFinancialData($i, $d) && ! $i->canEditFinancials()) {
            throw new InvoiceFinancialEditException('Hóa đơn đã có thanh toán nên không thể chỉnh sửa các khoản thu.');
        }

        if ($d['status'] !== $i->status && ! $i->canEditFinancials()) {
            throw new InvoiceFinancialEditException('Hóa đơn đã có thanh toán nên không thể thay đổi trạng thái thủ công.');
        }

        $oldRemaining = (float) $i->total_amount - (float) $i->paid_amount;
        $this->contract($i->room, $d['contract_id'] ?? null);
        $this->period($i->room, $d['billing_period'], $i->id);
        if (($d['discount_amount'] ?? 0) > $i->subtotal) {
            throw ValidationException::withMessages(['discount_amount' => 'The discount amount cannot exceed the subtotal.']);
        }
        if ($d['status'] === 'CANCELLED' && $i->paid_amount > 0) {
            throw ValidationException::withMessages(['status' => 'An invoice with payments cannot be cancelled.']);
        }
        if ($i->paid_amount > $i->subtotal - ($d['discount_amount'] ?? 0)) {
            throw ValidationException::withMessages(['discount_amount' => 'The total amount cannot be lower than the paid amount.']);
        }
        $i->update([...$d, 'total_amount' => $i->subtotal - ($d['discount_amount'] ?? 0)]);
        $updatedInvoice = $i->fresh();
        app(PayOSService::class)->invalidateRequestsForAmountChange(
            $updatedInvoice,
            $oldRemaining,
            (float) $updatedInvoice->total_amount - (float) $updatedInvoice->paid_amount,
        );

        return ApiResponse::success(null, 'Invoice updated successfully.');
    }

    public function destroy(Request $r, int $id): JsonResponse
    {
        $i = $this->invoice($r, $id);
        if ($i->status !== 'DRAFT') {
            throw ValidationException::withMessages(['status' => 'Only DRAFT invoices can be deleted.']);
        }$i->delete();

        return ApiResponse::success(null, 'Invoice deleted successfully.');
    }

    public function generate(Request $r, int $id, BillingService $billing): JsonResponse
    {
        $billing->generateForInvoice($this->invoice($r, $id));

        return ApiResponse::success(null, 'Invoice generated successfully.');
    }

    private function room(Request $r, int $id): Room
    {
        return Room::whereHas('boardingHouse', fn ($q) => $q->where('landlord_id', $r->user()->landlord->id))->findOrFail($id);
    }

    private function invoice(Request $r, int $id): Invoice
    {
        return Invoice::whereHas('room.boardingHouse', fn ($q) => $q->where('landlord_id', $r->user()->landlord->id))->findOrFail($id);
    }

    private function contract(Room $room, ?int $id): void
    {
        if ($id && ! Contract::where('room_id', $room->id)->whereKey($id)->exists()) {
            throw ValidationException::withMessages(['contract_id' => 'The contract must belong to this room.']);
        }
    }

    private function period(Room $room, string $date, ?int $ignore = null): void
    {
        if (Invoice::where('room_id', $room->id)->whereDate('billing_period', $date)->where('status', '!=', 'CANCELLED')->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists()) {
            throw ValidationException::withMessages(['billing_period' => 'An invoice already exists for this billing period.']);
        }
    }

    private function changesFinancialData(Invoice $invoice, array $data): bool
    {
        return (int) ($data['contract_id'] ?? 0) !== (int) ($invoice->contract_id ?? 0)
            || $data['billing_period'] !== $invoice->billing_period->format('Y-m-d')
            || (float) ($data['discount_amount'] ?? 0) !== (float) $invoice->discount_amount;
    }
}
