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
use App\Services\InvoiceContractEligibility;
use App\Services\InvoicePaymentInfoService;
use App\Services\PayOSService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceController extends Controller
{
    public function index(Request $r, int $roomId, BoardingHouseAccessService $access): JsonResponse
    {
        $filters = $r->validate([
            'search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['DRAFT', 'UNPAID', 'PARTIALLY_PAID', 'PAID', 'CANCELLED'])],
            'month' => ['nullable', 'integer', 'between:1,12'], 'year' => ['nullable', 'integer', 'between:2000,2100'], 'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $room = $access->room($r->user(), $roomId);
        $invoices = $room->invoices()->with(['contract:id,room_id,tenant_id,contract_code,status', 'contract.tenant:id,full_name'])
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $term = '%'.addcslashes(trim($search), '%_\\').'%';
                $query->where(function ($matches) use ($term): void {
                    $matches->where('invoice_code', 'like', $term)
                        ->orWhereHas('room', fn ($room) => $room->where('room_code', 'like', $term)->orWhere('room_name', 'like', $term))
                        ->orWhereHas('contract.tenant', fn ($tenant) => $tenant->where('full_name', 'like', $term));
                });
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['month'] ?? null, fn ($query, int $month) => $query->whereMonth('billing_period', $month))
            ->when($filters['year'] ?? null, fn ($query, int $year) => $query->whereYear('billing_period', $year))
            ->orderByDesc('billing_period')->orderByDesc('id')->paginate(20);

        return ApiResponse::success(['items' => $invoices->items(), 'pagination' => ['current_page' => $invoices->currentPage(), 'last_page' => $invoices->lastPage(), 'per_page' => $invoices->perPage(), 'total' => $invoices->total()]], 'Lấy danh sách hóa đơn thành công.');
    }

    public function store(StoreInvoiceRequest $r, int $roomId, ContractService $contracts): JsonResponse
    {
        $room = $this->room($r, $roomId);
        $d = $r->validated();
        $d['billing_period'] = date('Y-m-01', strtotime($d['billing_period']));

        return DB::transaction(function () use ($room, $d, $contracts): JsonResponse {
            Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();
            $contract = isset($d['contract_id']) ? app(InvoiceContractEligibility::class)->assertEligible($room, $d['contract_id'], $d['billing_period']) : null;
            // Explicit invoice dates win; the contract supplies only a creation default.
            if (($d['due_date'] ?? null) === null && $contract !== null) {
                $d['due_date'] = $contracts->scheduleDate($d['billing_period'], $contract->payment_due_day);
            }
            $this->period($room, $d['billing_period'], null, $contract?->id);
            $room->invoices()->create([...$d, 'subtotal' => 0, 'total_amount' => 0, 'paid_amount' => 0]);

            return ApiResponse::success(null, 'Tạo hóa đơn thành công.', 201);
        });
    }

    public function eligibleContracts(Request $request, int $roomId, BoardingHouseAccessService $access, InvoiceContractEligibility $eligibility): JsonResponse
    {
        $period = $request->validate(['billing_period' => ['required', 'date']])['billing_period'];
        $room = $access->room($request->user(), $roomId);
        if (($warning = $eligibility->roomWarning($room)) !== null) {
            return ApiResponse::success(['room' => $room->only(['id', 'room_code', 'room_type']), 'contracts' => [], 'warnings' => [['contract_id' => null, 'contract_code' => $room->room_code, 'message' => $warning]]]);
        }
        $owner = $room->boardingHouse->landlord_id;
        $contracts = $room->contracts()->where('landlord_id', $owner)->where('status', 'ACTIVE')
            ->whereHas('tenant', fn ($query) => $query->where('landlord_id', $owner))
            ->with(['tenant:id,full_name', 'occupants', 'bed.roomTenants', 'room'])->latest('id')->get();
        $options = [];
        $warnings = [];
        foreach ($contracts as $contract) {
            if (($warning = $eligibility->warning($contract)) !== null) {
                $warnings[] = ['contract_id' => $contract->id, 'contract_code' => $contract->contract_code, 'message' => $warning];
            }
            $reason = $eligibility->reason($contract, $period);
            if ($reason !== null) {
                continue;
            }
            $options[] = [...$contract->only(['id', 'room_id', 'contract_code', 'status', 'start_date', 'end_date']),
                'start_date' => $contract->start_date?->toDateString(), 'end_date' => $contract->end_date?->toDateString(),
                'room_code' => $room->room_code, 'bed' => $contract->bed?->only(['id', 'bed_code']),
                'tenant' => ['full_name' => $contract->tenant?->full_name]];
        }

        return ApiResponse::success(['room' => $room->only(['id', 'room_code', 'room_type']), 'contracts' => $options, 'warnings' => $warnings]);
    }

    public function show(Request $r, int $id, BoardingHouseAccessService $access): JsonResponse
    {
        $invoice = Invoice::query()->whereHas('room', fn ($query) => $query->whereIn('boarding_house_id', $access->houses($r->user())->select('boarding_houses.id')))
            ->with(['room.boardingHouse', 'contract.tenant', 'items', 'payosPaymentRequest'])->findOrFail($id);
        $invoice->setAttribute('can_manage', $access->isOwner($r->user(), $invoice->room->boardingHouse));
        $invoice->setAttribute('payos_available', app(PayOSService::class)->availableFor($invoice->room->boardingHouse->landlord));
        $invoice->setAttribute('payment_profile', app(InvoicePaymentInfoService::class)->forInvoice($invoice));

        return ApiResponse::success($invoice, 'Lấy thông tin hóa đơn thành công.');
    }

    public function paymentQr(Request $r, int $id, BoardingHouseAccessService $access, InvoicePaymentInfoService $info): StreamedResponse
    {
        $invoice = Invoice::query()->whereHas('room', fn ($query) => $query->whereIn('boarding_house_id', $access->houses($r->user())->select('boarding_houses.id')))->findOrFail($id);

        return $info->invoiceQr($invoice);
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
        if ($i->status === 'DRAFT' && $i->contract_id !== null && ! isset($d['contract_id'])) {
            throw ValidationException::withMessages(['contract_id' => 'Vui lòng chọn hợp đồng của người thuê hiện tại cho hóa đơn định kỳ.']);
        }
        if (isset($d['contract_id']) && ((int) $d['contract_id'] !== (int) $i->contract_id || $d['billing_period'] !== $i->billing_period->toDateString())) {
            app(InvoiceContractEligibility::class)->assertEligible($i->room, $d['contract_id'], $d['billing_period']);
        } else {
            $this->contract($i->room, $d['contract_id'] ?? null);
        }
        $this->period($i->room, $d['billing_period'], $i->id, $d['contract_id'] ?? null);
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

    private function period(Room $room, string $date, ?int $ignore = null, ?int $contractId = null): void
    {
        if (Invoice::where('room_id', $room->id)->whereDate('billing_period', $date)->where('status', '!=', 'CANCELLED')->when($ignore, fn ($q) => $q->whereKeyNot($ignore))
            ->when($room->room_type === 'DORMITORY' && $contractId !== null, fn ($query) => $query->where(fn ($scope) => $scope->where('contract_id', $contractId)->orWhereNull('contract_id')))->exists()) {
            throw ValidationException::withMessages(['billing_period' => $room->room_type === 'DORMITORY' ? 'Hợp đồng/giường đã có hóa đơn cho kỳ này. Vui lòng chọn kỳ khác.' : 'Phòng đã có hóa đơn cho kỳ này. Vui lòng chọn kỳ khác.']);
        }
    }

    private function changesFinancialData(Invoice $invoice, array $data): bool
    {
        return (int) ($data['contract_id'] ?? 0) !== (int) ($invoice->contract_id ?? 0)
            || $data['billing_period'] !== $invoice->billing_period->format('Y-m-d')
            || (float) ($data['discount_amount'] ?? 0) !== (float) $invoice->discount_amount;
    }
}
