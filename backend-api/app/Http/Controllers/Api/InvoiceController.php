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
use App\Services\BoardingHouseAccessService;
use App\Services\ContractService;
use App\Services\PayOSService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    public function index(Request $r, int $roomId, BoardingHouseAccessService $access): JsonResponse
    {
        return ApiResponse::success($access->room($r->user(), $roomId)->invoices()->with('contract:id,room_id,tenant_id,contract_code,status')->orderByDesc('billing_period')->get(), 'Lấy danh sách hóa đơn thành công.');
    }

    public function store(StoreInvoiceRequest $r, int $roomId, ContractService $contracts): JsonResponse
    {
        $room = $this->room($r, $roomId);
        $d = $r->validated();
        $contract = $this->contract($room, $d['contract_id'] ?? null);
        $d['billing_period'] = date('Y-m-01', strtotime($d['billing_period']));
        // Explicit invoice dates win; the contract supplies only a creation default.
        if (($d['due_date'] ?? null) === null && $contract !== null) {
            $d['due_date'] = $contracts->scheduleDate($d['billing_period'], $contract->payment_due_day);
        }
        $this->period($room, $d['billing_period']);
        $room->invoices()->create([...$d, 'subtotal' => 0, 'total_amount' => 0, 'paid_amount' => 0]);

        return ApiResponse::success(null, 'Tạo hóa đơn thành công.', 201);
    }

    public function show(Request $r, int $id, BoardingHouseAccessService $access): JsonResponse
    {
        $invoice = Invoice::query()->whereHas('room', fn ($query) => $query->whereIn('boarding_house_id', $access->houses($r->user())->select('boarding_houses.id')))
            ->with(['room.boardingHouse', 'contract.tenant', 'items', 'payosPaymentRequest'])->findOrFail($id);
        $invoice->setAttribute('can_manage', $access->isOwner($r->user(), $invoice->room->boardingHouse));
        $invoice->setAttribute('payos_available', app(PayOSService::class)->availableFor($invoice->room->boardingHouse->landlord));
        $invoice->setAttribute('payment_profile', $invoice->room->boardingHouse->landlord->paymentProfile?->only(['bank_name', 'bank_account_name', 'bank_account_number']));

        return ApiResponse::success($invoice, 'Lấy thông tin hóa đơn thành công.');
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
            throw ValidationException::withMessages(['discount_amount' => 'Số tiền giảm giá không được vượt quá tạm tính.']);
        }
        if ($d['status'] === 'CANCELLED' && $i->paid_amount > 0) {
            throw ValidationException::withMessages(['status' => 'Hóa đơn đã có thanh toán nên không thể hủy.']);
        }
        if ($i->paid_amount > $i->subtotal - ($d['discount_amount'] ?? 0)) {
            throw ValidationException::withMessages(['discount_amount' => 'Tổng tiền hóa đơn không được nhỏ hơn số tiền đã thanh toán.']);
        }
        $i->update([...$d, 'total_amount' => $i->subtotal - ($d['discount_amount'] ?? 0)]);
        $updatedInvoice = $i->fresh();
        app(PayOSService::class)->invalidateRequestsForAmountChange(
            $updatedInvoice,
            $oldRemaining,
            (float) $updatedInvoice->total_amount - (float) $updatedInvoice->paid_amount,
        );

        return ApiResponse::success(null, 'Cập nhật hóa đơn thành công.');
    }

    public function destroy(Request $r, int $id): JsonResponse
    {
        $i = $this->invoice($r, $id);
        if ($i->status !== 'DRAFT') {
            throw ValidationException::withMessages(['status' => 'Chỉ có thể xóa hóa đơn ở trạng thái nháp.']);
        }$i->delete();

        return ApiResponse::success(null, 'Xóa hóa đơn thành công.');
    }

    public function generate(Request $r, int $id, BillingService $billing): JsonResponse
    {
        $billing->generateForInvoice($this->invoice($r, $id));

        return ApiResponse::success(null, 'Tạo các khoản thu tự động thành công.');
    }

    private function room(Request $r, int $id): Room
    {
        return Room::whereHas('boardingHouse', fn ($q) => $q->where('landlord_id', $r->user()->landlord?->id))->findOrFail($id);
    }

    private function invoice(Request $r, int $id): Invoice
    {
        return Invoice::whereHas('room.boardingHouse', fn ($q) => $q->where('landlord_id', $r->user()->landlord?->id))->findOrFail($id);
    }

    private function contract(Room $room, ?int $id): ?Contract
    {
        if ($id === null) {
            return null;
        }
        $contract = $room->contracts()->find($id);
        if ($contract === null) {
            throw ValidationException::withMessages(['contract_id' => 'Hợp đồng phải thuộc phòng này.']);
        }

        return $contract;
    }

    private function period(Room $room, string $date, ?int $ignore = null): void
    {
        if (Invoice::where('room_id', $room->id)->whereDate('billing_period', $date)->where('status', '!=', 'CANCELLED')->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists()) {
            throw ValidationException::withMessages(['billing_period' => 'Phòng đã có hóa đơn cho kỳ này. Vui lòng chọn kỳ khác.']);
        }
    }

    private function changesFinancialData(Invoice $invoice, array $data): bool
    {
        return (int) ($data['contract_id'] ?? 0) !== (int) ($invoice->contract_id ?? 0)
            || $data['billing_period'] !== $invoice->billing_period->format('Y-m-d')
            || (float) ($data['discount_amount'] ?? 0) !== (float) $invoice->discount_amount;
    }
}
