<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Exceptions\InvoiceFinancialEditException;
use App\Http\Controllers\Controller;
use App\Http\Requests\InvoiceItem\StoreInvoiceItemRequest;
use App\Http\Requests\InvoiceItem\UpdateInvoiceItemRequest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Room;
use App\Services\ContractSettlementPolicy;
use App\Services\PayOSService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class InvoiceItemController extends Controller
{
    public function store(StoreInvoiceItemRequest $r, int $invoiceId): JsonResponse
    {
        return $this->withInvoiceLock($r, $invoiceId, function (Invoice $invoice) use ($r): JsonResponse {
            $this->ensureFinancialsEditable($invoice);
            $d = $r->validated();
            $invoice->items()->create([...$d, 'amount' => $d['quantity'] * $d['unit_price'], 'source' => 'MANUAL']);
            $invoice->recalculateTotals();

            return ApiResponse::success(null, 'Tạo khoản thu thành công.', 201);
        });
    }

    public function update(UpdateInvoiceItemRequest $r, int $id): JsonResponse
    {
        $item = $this->item($r, $id);

        return $this->withInvoiceLock($r, $item->invoice_id, function (Invoice $invoice) use ($r, $id): JsonResponse {
            $item = $invoice->items()->lockForUpdate()->findOrFail($id);
            $this->ensureNotAllocated($item);
            $this->ensureFinancialsEditable($invoice);
            $d = $r->validated();
            $item->update([...$d, 'amount' => $d['quantity'] * $d['unit_price']]);
            $invoice->recalculateTotals();

            return ApiResponse::success(null, 'Cập nhật khoản thu thành công.');
        });
    }

    public function destroy(Request $r, int $id): JsonResponse
    {
        $item = $this->item($r, $id);

        return $this->withInvoiceLock($r, $item->invoice_id, function (Invoice $invoice) use ($id): JsonResponse {
            $item = $invoice->items()->lockForUpdate()->findOrFail($id);
            $this->ensureNotAllocated($item);
            $this->ensureFinancialsEditable($invoice);
            $item->delete();
            $invoice->recalculateTotals();

            return ApiResponse::success(null, 'Xóa khoản thu thành công.');
        });
    }

    private function withInvoiceLock(Request $request, int $id, Closure $operation): JsonResponse
    {
        $owned = $this->invoice($request, $id);
        $oldRemaining = 0.0;
        $response = DB::transaction(function () use ($owned, $operation, &$oldRemaining): JsonResponse {
            Room::query()->whereKey($owned->room_id)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($owned->id);
            app(ContractSettlementPolicy::class)->assertInvoiceWriteAllowed($invoice);
            $oldRemaining = (float) $invoice->total_amount - (float) $invoice->paid_amount;

            return $operation($invoice);
        }, 3);
        $updated = $owned->fresh();
        app(PayOSService::class)->invalidateRequestsForAmountChange($updated, $oldRemaining, (float) $updated->total_amount - (float) $updated->paid_amount);

        return $response;
    }

    private function invoice(Request $r, int $id): Invoice
    {
        return Invoice::whereHas('room.boardingHouse', fn ($q) => $q->where('landlord_id', $r->user()->landlord->id))->findOrFail($id);
    }

    private function item(Request $r, int $id): InvoiceItem
    {
        return InvoiceItem::whereHas('invoice.room.boardingHouse', fn ($q) => $q->where('landlord_id', $r->user()->landlord->id))->findOrFail($id);
    }

    private function ensureFinancialsEditable(Invoice $i): void
    {
        if (! $i->canEditFinancials()) {
            throw new InvoiceFinancialEditException('Hóa đơn đã có thanh toán nên không thể chỉnh sửa các khoản thu.');
        }
    }

    private function ensureNotAllocated(InvoiceItem $item): void
    {
        if ($item->source === 'AUTO' && Schema::hasTable('monthly_invoice_compositions') && DB::table('monthly_invoice_compositions')->where('invoice_id', $item->invoice_id)->exists()) {
            throw ValidationException::withMessages(['source' => 'Khoản AUTO thuộc snapshot hóa đơn tháng; cần quy trình điều chỉnh.']);
        }
        if (Schema::hasTable('utility_charge_allocations') && DB::table('utility_charge_allocations')->where('invoice_item_id', $item->id)->exists()) {
            throw ValidationException::withMessages(['source' => 'Khoản AUTO V2 đã liên kết phân bổ. Hãy sửa mốc chốt và tính lại hóa đơn nháp.']);
        }
    }
}
