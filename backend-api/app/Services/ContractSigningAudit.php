<?php

namespace App\Services;

use App\Models\ContractDocument;
use App\Models\ContractSignatureEvent;
use App\Models\ContractSigningRequest;
use Illuminate\Http\Request;

class ContractSigningAudit
{
    public function record(ContractDocument $document, string $event, ?Request $http = null, ?ContractSigningRequest $signing = null, array $metadata = []): void
    {
        $public = $http?->is('api/sign/*') ?? false;
        ContractSignatureEvent::create([
            'contract_document_id' => $document->id, 'signing_request_id' => $signing?->id,
            'actor_type' => $public ? 'TENANT' : ($http?->user() ? 'USER' : 'SYSTEM'),
            'actor_user_id' => $public ? null : $http?->user()?->id,
            'actor_tenant_id' => $public ? $signing?->signer_tenant_id : null,
            'event' => $event, 'ip_address' => $http?->ip(),
            'user_agent' => $http ? mb_substr((string) $http->userAgent(), 0, 500) : null,
            'metadata' => $metadata ?: null, 'created_at' => now(),
        ]);
    }
}
