<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Exceptions\InvoiceFinancialEditException;
use App\Http\Controllers\Controller;
use App\Http\Requests\InvoiceItem\StoreInvoiceItemRequest;
use App\Http\Requests\InvoiceItem\UpdateInvoiceItemRequest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\PayOSService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceItemController extends Controller
{
    public function store(StoreInvoiceItemRequest $r, int $invoiceId): JsonResponse
    {
        $i = $this->invoice($r, $invoiceId);
        $this->ensureFinancialsEditable($i);
        $oldRemaining = (float) $i->total_amount - (float) $i->paid_amount;
        $d = $r->validated();
        $i->items()->create([...$d, 'amount' => $d['quantity'] * $d['unit_price'], 'source' => 'MANUAL']);
        $i->recalculateTotals();
        $updatedInvoice = $i->fresh();
        app(PayOSService::class)->invalidateRequestsForAmountChange($updatedInvoice, $oldRemaining, (float) $updatedInvoice->total_amount - (float) $updatedInvoice->paid_amount);

        return ApiResponse::success(null, 'Tạo khoản thu thành công.', 201);
    }

    public function update(UpdateInvoiceItemRequest $r, int $id): JsonResponse
    {
        $item = $this->item($r, $id);
        $this->ensureFinancialsEditable($item->invoice);
        $oldRemaining = (float) $item->invoice->total_amount - (float) $item->invoice->paid_amount;
        $d = $r->validated();
        $item->update([...$d, 'amount' => $d['quantity'] * $d['unit_price']]);
        $item->invoice->recalculateTotals();
        $updatedInvoice = $item->invoice->fresh();
        app(PayOSService::class)->invalidateRequestsForAmountChange($updatedInvoice, $oldRemaining, (float) $updatedInvoice->total_amount - (float) $updatedInvoice->paid_amount);

        return ApiResponse::success(null, 'Cập nhật khoản thu thành công.');
    }

    public function destroy(Request $r, int $id): JsonResponse
    {
        $item = $this->item($r, $id);
        $this->ensureFinancialsEditable($item->invoice);
        $invoice = $item->invoice;
        $oldRemaining = (float) $invoice->total_amount - (float) $invoice->paid_amount;
        $item->delete();
        $invoice->recalculateTotals();
        $updatedInvoice = $invoice->fresh();
        app(PayOSService::class)->invalidateRequestsForAmountChange($updatedInvoice, $oldRemaining, (float) $updatedInvoice->total_amount - (float) $updatedInvoice->paid_amount);

        return ApiResponse::success(null, 'Xóa khoản thu thành công.');
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
}
