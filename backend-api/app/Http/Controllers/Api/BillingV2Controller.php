<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\BoardingHouse;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Room;
use App\Models\UtilityMeter;
use App\Services\BillingV2Access;
use App\Services\BillingV2Evidence;
use App\Services\ContractSettlementPolicy;
use App\Services\MonthlyDraftRecalculation;
use App\Services\MonthlyInvoiceBilling;
use App\Services\UtilityPeriodBilling;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BillingV2Controller extends Controller
{
    public function status(Request $request, BillingV2Access $access): JsonResponse
    {
        return ApiResponse::success(['enabled' => $access->enabledFor($request->user())]);
    }

    public function previewMonthlyDraft(Request $request, int $id, MonthlyDraftRecalculation $recalculation): JsonResponse
    {
        $room = $this->room($request, $id);
        $data = $request->validate([
            'billing_period' => ['required', 'date_format:Y-m-01'],
            'usage_start_at' => ['required', 'date_format:Y-m-d H:i:s'], 'usage_end_at' => ['required', 'date_format:Y-m-d H:i:s'],
            'confirmations' => ['sometimes', 'array', 'max:1000'],
            'confirmations.*.contract_id' => ['required', 'integer', 'distinct'],
            'confirmations.*.rent_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999'],
            'confirmations.*.reason' => ['required', 'string', 'min:5', 'max:1000'], 'confirmations.*.extra_service_confirmed' => ['sometimes', 'boolean'], 'confirmations.*.services' => ['sometimes', 'array'],
            'confirmations.*.services.*.term_id' => ['required', 'integer', 'distinct'],
            'confirmations.*.services.*.quantity' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999'],
            'confirmations.*.services.*.amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999'],
        ]);
        foreach ($data['confirmations'] ?? [] as $confirmation) {
            $contract = $room->contracts()->findOrFail($confirmation['contract_id']);
            foreach ($confirmation['services'] ?? [] as $service) {
                $contract->agreedServices()->findOrFail($service['term_id']);
            }
        }
        abort_if(CarbonImmutable::parse($data['usage_start_at'], 'Asia/Ho_Chi_Minh')->greaterThanOrEqualTo(CarbonImmutable::parse($data['usage_end_at'], 'Asia/Ho_Chi_Minh')), 422, 'Thời điểm kết thúc phải sau thời điểm bắt đầu kỳ sử dụng.');

        return ApiResponse::success($recalculation->preview($room, $data['billing_period'], $data['usage_start_at'], $data['usage_end_at'], $data['confirmations'] ?? [], $request->user()->id), 'Đã kiểm tra bản xem trước; chưa cập nhật khoản thu.');
    }

    public function confirmMonthlyDraft(Request $request, int $id, string $token, MonthlyDraftRecalculation $recalculation): JsonResponse
    {
        $room = $this->room($request, $id);
        $data = $request->validate(['confirmed' => ['required', 'accepted'], 'reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return ApiResponse::success($recalculation->confirm($room, $token, $data['reason'], $request->user()->id), 'Đã cập nhật tính toán draft có lịch sử; chưa phát hành.');
    }

    public function monthlyOptions(Request $request, int $id): JsonResponse
    {
        $house = BoardingHouse::whereHas('landlord', fn ($q) => $q->where('user_id', $request->user()->id))->findOrFail($id);
        $data = $request->validate(['billing_period' => ['required', 'date_format:Y-m-01']]);
        $start = CarbonImmutable::parse($data['billing_period']);
        $contracts = Contract::whereIn('room_id', $house->rooms()->select('id'))->whereIn('status', ['ACTIVE', 'TERMINATED', 'EXPIRED'])
            ->whereDate('start_date', '<', $start->addMonth())->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $start))
            ->with(['agreedServices', 'room:id,room_code,room_type', 'bed:id,bed_code'])->get(['id', 'room_id', 'contract_type', 'bed_id', 'contract_code', 'monthly_rent', 'start_date', 'end_date']);

        return ApiResponse::success($contracts);
    }

    public function monthly(Request $request, int $id, MonthlyInvoiceBilling $billing): JsonResponse
    {
        $house = BoardingHouse::whereHas('landlord', fn ($q) => $q->where('user_id', $request->user()->id))->findOrFail($id);
        $data = $request->validate([
            'billing_period' => ['required', 'date_format:Y-m-01'],
            'usage_start_at' => ['required', 'date_format:Y-m-d H:i:s'], 'usage_end_at' => ['required', 'date_format:Y-m-d H:i:s'],
            'confirmations' => ['sometimes', 'array', 'max:1000'],
            'confirmations.*.contract_id' => ['required', 'integer', 'distinct'],
            'confirmations.*.rent_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999'],
            'confirmations.*.reason' => ['required', 'string', 'min:5', 'max:1000'],
            'confirmations.*.extra_service_confirmed' => ['sometimes', 'boolean'],
            'confirmations.*.services' => ['sometimes', 'array'],
            'confirmations.*.services.*.term_id' => ['required', 'integer', 'distinct'],
            'confirmations.*.services.*.quantity' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999'],
            'confirmations.*.services.*.amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999'],
        ]);
        foreach ($data['confirmations'] ?? [] as $confirmation) {
            abort_unless(Contract::whereKey($confirmation['contract_id'])->whereIn('room_id', $house->rooms()->select('id'))->exists(), 404);
            foreach ($confirmation['services'] ?? [] as $service) {
                abort_unless(Contract::findOrFail($confirmation['contract_id'])->agreedServices()->whereKey($service['term_id'])->exists(), 404);
            }
        }
        abort_if(CarbonImmutable::parse($data['usage_start_at'], 'Asia/Ho_Chi_Minh')->greaterThanOrEqualTo(CarbonImmutable::parse($data['usage_end_at'], 'Asia/Ho_Chi_Minh')), 422, 'Thời điểm kết thúc phải sau thời điểm bắt đầu kỳ sử dụng.');

        return ApiResponse::success($billing->generate($house, $data['billing_period'], $data['usage_start_at'], $data['usage_end_at'], $data['confirmations'] ?? [], $request->user()->id), 'Đã kiểm tra kỳ hóa đơn; xem kết quả từng phòng. Chưa phát hành hóa đơn.');
    }

    public function monthlyRoom(Request $request, int $id, MonthlyInvoiceBilling $billing): JsonResponse
    {
        $room = $this->room($request, $id);
        $data = $request->validate([
            'billing_period' => ['required', 'date_format:Y-m-01'],
            'usage_start_at' => ['required', 'date_format:Y-m-d H:i:s'], 'usage_end_at' => ['required', 'date_format:Y-m-d H:i:s'],
            'confirmations' => ['sometimes', 'array', 'max:1000'],
            'confirmations.*.contract_id' => ['required', 'integer', 'distinct'],
            'confirmations.*.rent_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999'],
            'confirmations.*.reason' => ['required', 'string', 'min:5', 'max:1000'],
            'confirmations.*.extra_service_confirmed' => ['sometimes', 'boolean'],
            'confirmations.*.services' => ['sometimes', 'array'],
            'confirmations.*.services.*.term_id' => ['required', 'integer', 'distinct'],
            'confirmations.*.services.*.quantity' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999'],
            'confirmations.*.services.*.amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999'],
        ]);
        foreach ($data['confirmations'] ?? [] as $confirmation) {
            abort_unless(Contract::whereKey($confirmation['contract_id'])->whereIn('room_id', [$room->id])->exists(), 404);
            foreach ($confirmation['services'] ?? [] as $service) {
                abort_unless(Contract::findOrFail($confirmation['contract_id'])->agreedServices()->whereKey($service['term_id'])->exists(), 404);
            }
        }
        abort_if(CarbonImmutable::parse($data['usage_start_at'], 'Asia/Ho_Chi_Minh')->greaterThanOrEqualTo(CarbonImmutable::parse($data['usage_end_at'], 'Asia/Ho_Chi_Minh')), 422, 'Thời điểm kết thúc phải sau thời điểm bắt đầu kỳ sử dụng.');
        return ApiResponse::success([$billing->generateForRoom($room, $data['billing_period'], $data['usage_start_at'], $data['usage_end_at'], $data['confirmations'] ?? [], $request->user()->id)], 'Đã kiểm tra riêng phòng; chỉ tạo hóa đơn nháp nếu đủ điều kiện.');
    }

    public function checkpoint(Request $request, int $id, BillingV2Evidence $evidence): JsonResponse
    {
        $meter = UtilityMeter::whereHas('room.boardingHouse.landlord', fn ($q) => $q->where('user_id', $request->user()->id))->findOrFail($id);
        $data = $request->validate(['kind' => ['required', 'in:PERIOD_BOUNDARY,CHECK_IN,CHECK_OUT'], 'contract_id' => ['nullable', 'integer'], 'observed_at' => ['required', 'date_format:Y-m-d H:i:s'], 'reading_value' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99']]);

        return ApiResponse::success(['id' => $evidence->checkpoint($meter, $data, $request->user()->id)], 'Xác nhận mốc chốt thành công.');
    }

    /** Save both readings of one meter as one transaction; do not create an invoice. */
    public function periodBoundaries(Request $request, int $id, BillingV2Evidence $evidence): JsonResponse
    {
        $meter = UtilityMeter::whereHas('room.boardingHouse.landlord', fn ($q) => $q->where('user_id', $request->user()->id))->findOrFail($id);
        $data = $request->validate([
            'start_observed_at' => ['required', 'date_format:Y-m-d H:i:s'],
            'end_observed_at' => ['required', 'date_format:Y-m-d H:i:s', 'after:start_observed_at'],
            'start_reading_value' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'end_reading_value' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99', 'gte:start_reading_value'],
        ]);
        $ids = DB::transaction(function () use ($evidence, $meter, $data, $request) {
            $first = $evidence->checkpoint($meter, [
                'kind' => 'PERIOD_BOUNDARY', 'contract_id' => null,
                'observed_at' => $data['start_observed_at'], 'reading_value' => $data['start_reading_value'],
            ], $request->user()->id);
            $last = $evidence->checkpoint($meter, [
                'kind' => 'PERIOD_BOUNDARY', 'contract_id' => null,
                'observed_at' => $data['end_observed_at'], 'reading_value' => $data['end_reading_value'],
            ], $request->user()->id);

            return ['start_checkpoint_id' => $first, 'end_checkpoint_id' => $last];
        }, 3);

        return ApiResponse::success($ids, 'Đã lưu cả hai mốc công tơ. Chưa tính tiền hoặc phát hành hóa đơn.');
    }

    /** Explicit correction of two previously confirmed meter boundaries, with immutable history. */
    public function correctPeriodBoundaries(Request $request, int $id, BillingV2Evidence $evidence): JsonResponse
    {
        $meter = UtilityMeter::whereHas('room.boardingHouse.landlord', fn ($q) => $q->where('user_id', $request->user()->id))->findOrFail($id);
        $data = $request->validate([
            'start_checkpoint_id' => ['required', 'integer'], 'end_checkpoint_id' => ['required', 'integer', 'different:start_checkpoint_id'],
            'start_observed_at' => ['required', 'date_format:Y-m-d H:i:s'],
            'end_observed_at' => ['required', 'date_format:Y-m-d H:i:s', 'after:start_observed_at'],
            'start_reading_value' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'end_reading_value' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99', 'gte:start_reading_value'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'request_key' => ['required', 'uuid'],
        ]);
        return ApiResponse::success($evidence->correctPeriodBoundaries($meter, $data, $request->user()->id),
            'Đã lưu phiên bản đính chính mốc. Nếu hóa đơn nháp đã tính, phải xem trước và xác nhận tính lại trước khi phát hành.');
    }

    public function checkout(Request $request, int $id, BillingV2Evidence $evidence): JsonResponse
    {
        $contract = $this->contract($request, $id);
        $data = $request->validate(['actual_ended_at' => ['required', 'date_format:Y-m-d H:i:s'], 'reason' => ['required', 'in:NORMAL_EXPIRY,MUTUAL_EARLY_END,TENANT_ABANDONMENT'], 'confirm_no_held_deposit' => ['sometimes', 'boolean'], 'readings' => ['present', 'array'], 'readings.*.utility_meter_id' => ['required', 'integer', 'distinct'], 'readings.*.reading_value' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99']]);
        $evidence->checkout($contract, $data, $request->user()->id);

        return ApiResponse::success(null, 'Xác nhận trả phòng thành công.');
    }

    public function confirmSettlement(Request $request, int $id, ContractSettlementPolicy $policy): JsonResponse
    {
        $contract = $this->contract($request, $id);
        $data = $request->validate(['confirm_no_held_deposit' => ['required', 'accepted'], 'reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $policy->confirmNoHeldDeposit($contract, $request->user()->id, $data['reason']);

        return ApiResponse::success(null, 'Đã ghi nhận xác nhận của chủ trọ; không tạo giao dịch mất cọc giả.');
    }

    public function deposit(Request $request, int $id, BillingV2Evidence $evidence): JsonResponse
    {
        $contract = $this->contract($request, $id);
        $data = $request->validate(['event' => ['required', 'in:RECEIVED,REFUNDED'], 'amount' => ['required', 'integer', 'min:1', 'max:999999999999999'], 'request_key' => ['required', 'string', 'max:80', 'not_regex:/^CHECK_OUT:/'], 'occurred_at' => ['required', 'date_format:Y-m-d H:i:s']]);

        return ApiResponse::success(['id' => $evidence->deposit($contract, $data, $request->user()->id), 'held_amount' => $evidence->heldDeposit($contract)], 'Ghi nhận sổ cọc thành công.');
    }

    public function generate(Request $request, int $id, UtilityPeriodBilling $billing): JsonResponse
    {
        $room = $this->room($request, $id);
        $data = $request->validate([
            'billing_period' => ['required', 'date_format:Y-m-01'],
            'usage_start_at' => ['required', 'date_format:Y-m-d H:i:s'],
            'usage_end_at' => ['required', 'date_format:Y-m-d H:i:s'],
            'meter_periods' => ['sometimes', 'array', 'max:100'],
            'meter_periods.*.utility_meter_id' => ['required', 'integer', 'distinct'],
            'meter_periods.*.usage_start_at' => ['required', 'date_format:Y-m-d H:i:s'],
            'meter_periods.*.usage_end_at' => ['required', 'date_format:Y-m-d H:i:s'],
        ]);
        abort_if(CarbonImmutable::parse($data['usage_start_at'], 'Asia/Ho_Chi_Minh')->greaterThanOrEqualTo(CarbonImmutable::parse($data['usage_end_at'], 'Asia/Ho_Chi_Minh')), 422, 'Thời điểm kết thúc phải sau thời điểm bắt đầu kỳ sử dụng.');

        return ApiResponse::success($billing->generate(
            $room, $data['billing_period'], $data['usage_start_at'], $data['usage_end_at'],
            $request->user()->id, $data['meter_periods'] ?? []
        ), 'Tính quyết toán điện nước V2 thành công.');
    }

    public function issue(Request $request, int $id, UtilityPeriodBilling $billing): JsonResponse
    {
        $invoice = Invoice::whereHas('room.boardingHouse.landlord', fn ($query) => $query->where('user_id', $request->user()->id))
            ->whereIn('id', DB::table('invoice_obligations')->select('invoice_id'))->findOrFail($id);
        $billing->issue($invoice);

        return ApiResponse::success(null, 'Phát hành hóa đơn V2 thành công.');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $room = $this->room($request, $id);
        $data = $request->validate(['billing_period' => ['required', 'date_format:Y-m-01']]);
        $periods = DB::table('utility_billing_periods')->whereIn('utility_meter_id', $room->utilityMeters()->withTrashed()->select('id'))->where('billing_period', $data['billing_period'])->get();

        return ApiResponse::success(['room' => $room->only(['id', 'room_code', 'room_type']), 'meters' => $room->utilityMeters()->where('is_active', true)->with('service:id,name,unit,type')->get(),
            'contracts' => $room->contracts()->whereIn('status', ['ACTIVE', 'TERMINATED', 'EXPIRED'])->get(['id', 'contract_code', 'status', 'start_date', 'end_date']),
            'checkpoints' => DB::table('utility_checkpoints')->whereIn('utility_meter_id', $room->utilityMeters()->select('id'))->orderByDesc('revision')->get()->unique(fn ($row) => $row->utility_meter_id.':'.$row->scope_key)->values(),
            // Read-only V1 history for anchoring/checking measurements. Never convert these rows into V2 evidence automatically.
            'legacy_readings' => DB::table('utility_readings')->whereIn('utility_meter_id', $room->utilityMeters()->withTrashed()->select('id'))->orderBy('reading_date')->orderBy('id')->get(['id', 'utility_meter_id', 'reading_date', 'reading_value']),
            'checkpoint_history' => DB::table('utility_checkpoints')->whereIn('utility_meter_id', $room->utilityMeters()->select('id'))->orderByDesc('id')->limit(100)->get(),
            'checkpoint_corrections' => DB::table('utility_checkpoint_corrections')->whereIn('utility_meter_id', $room->utilityMeters()->select('id'))->orderByDesc('id')->limit(100)->get(),
            'deposits' => DB::table('contract_deposit_entries')->whereIn('contract_id', $room->contracts()->select('id'))->orderBy('id')->get(),
            'settlements' => DB::table('contract_settlements')->whereIn('contract_id', $room->contracts()->select('id'))->get(),
            'settlement_usages' => DB::table('contract_settlement_usages')->whereIn('contract_settlement_id', DB::table('contract_settlements')->whereIn('contract_id', $room->contracts()->select('id'))->select('id'))->get(),
            'periods' => $periods, 'allocations' => DB::table('utility_charge_allocations')->whereIn('utility_billing_period_id', $periods->pluck('id'))->get(),
            'invoices' => DB::table('invoice_obligations')->join('invoices', 'invoices.id', '=', 'invoice_obligations.invoice_id')->where('invoice_obligations.room_id', $room->id)->where('invoice_obligations.billing_period', $data['billing_period'])->select('invoice_obligations.*', 'invoices.status')->get()]);
    }

    private function room(Request $request, int $id): Room
    {
        return Room::whereHas('boardingHouse.landlord', fn ($q) => $q->where('user_id', $request->user()->id))->findOrFail($id);
    }

    private function contract(Request $request, int $id): Contract
    {
        return Contract::whereHas('room.boardingHouse.landlord', fn ($q) => $q->where('user_id', $request->user()->id))->findOrFail($id);
    }
}
