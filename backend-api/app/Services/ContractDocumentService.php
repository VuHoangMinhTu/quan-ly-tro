<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractDocument;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ContractDocumentService
{
    public function __construct(private ContractTemplateService $templates, private ContractTemplateRenderer $renderer) {}

    /** Runs inside ContractService's room-locked activation transaction. */
    public function capture(Contract $contract, array $snapshot): ContractDocument
    {
        $existing = $contract->document()->first();
        if ($existing) {
            return $existing;
        }
        $template = $this->templates->select($contract->room->boardingHouse->landlord_id, $contract->contract_template_id, true);
        $rendered = $this->renderer->render($template->content_html, $snapshot);
        $contract->contract_template_id = $template->id;
        $contract->save();

        $document = ContractDocument::create([
            'contract_id' => $contract->id, 'contract_template_id' => $template->id,
            'template_key' => $template->template_key, 'template_version' => $template->version, 'template_name' => $template->name,
            'source_snapshot' => $snapshot, 'rendered_html' => $rendered,
            'document_hash' => hash('sha256', $rendered), 'finalized_at' => now(),
        ]);
        app(ContractSigningAudit::class)->record($document, 'DOCUMENT_FINALIZED');

        return $document;
    }

    public function document(User $user, int $id): ContractDocument
    {
        $contract = app(ContractService::class)->find($user, $id);
        $document = $contract->document()->firstOrFail();
        if (! hash_equals($document->document_hash, hash('sha256', $document->rendered_html))) {
            throw ValidationException::withMessages(['document' => 'Nội dung tài liệu không khớp bản đã chốt. Vui lòng liên hệ chủ trọ để kiểm tra.']);
        }

        return $document;
    }

    public function preview(User $user, int $id): array
    {
        $contract = app(ContractService::class)->find($user, $id);
        if ($contract->status !== 'DRAFT') {
            return [...$this->document($user, $id)->toArray(), 'is_preview' => false];
        }
        $template = $this->templates->select($contract->room->boardingHouse->landlord_id, $contract->contract_template_id);
        $snapshot = app(ContractService::class)->previewSnapshot($user, $id);

        return ['rendered_html' => $this->renderer->render($template->content_html, $snapshot), 'source_snapshot' => $snapshot,
            'template_name' => $template->name, 'template_version' => $template->version,
            'is_preview' => true, 'finalized_at' => null];
    }

    public function finalize(User $user, int $id): void
    {
        $contract = app(ContractService::class)->find($user, $id);
        if ($contract->document()->exists()) {
            $this->document($user, $id);

            return;
        }
        if ($contract->status !== 'DRAFT') {
            throw ValidationException::withMessages(['document' => 'Hợp đồng cũ chưa có tài liệu được chốt. Không thể tự tạo lại và coi đây là bản đã xác nhận trước đó.']);
        }
        app(ContractService::class)->save($user, $contract->room_id, ['status' => 'ACTIVE'], $id);
    }
}
