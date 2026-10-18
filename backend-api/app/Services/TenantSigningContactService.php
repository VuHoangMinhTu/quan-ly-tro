<?php

namespace App\Services;

use App\Models\ContractDocument;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TenantSigningContactService
{
    public function __construct(private ContractSigningAudit $audit) {}

    public function update(Request $http, Tenant $tenant, array $data): void
    {
        DB::transaction(function () use ($http, $tenant, $data): void {
            // Signing also locks document before reading the current tenant contact.
            // Keep that order so email changes cannot race a signature or deadlock it.
            $documents = array_key_exists('email', $data)
                ? ContractDocument::query()->whereHas('contract', fn (Builder $query) => $query->where('tenant_id', $tenant->id)
                    ->whereHas('room.boardingHouse', fn (Builder $house) => $house->where('landlord_id', $tenant->landlord_id)))
                    ->orderBy('id')->lockForUpdate()->get()
                : collect();
            $current = Tenant::query()->where('landlord_id', $tenant->landlord_id)->lockForUpdate()->findOrFail($tenant->id);
            $previousEmail = trim((string) $current->email);
            $current->update($data);
            if ($previousEmail === trim((string) $current->email)) {
                return;
            }
            foreach ($documents as $document) {
                $requests = $document->signingRequests()->where('signer_role', 'TENANT')
                    ->whereIn('status', ['PENDING', 'OTP_VERIFIED'])->orderBy('id')->lockForUpdate()->get();
                foreach ($requests as $request) {
                    $request->update(['status' => 'REVOKED', 'active_key' => null, 'revoked_at' => now(),
                        'otp_hash' => null, 'otp_verified_at' => null, 'verification_hash' => null]);
                    $this->audit->record($document, 'REQUEST_REVOKED', $http, $request, ['reason' => 'Email liên hệ của người thuê đã thay đổi.']);
                }
            }
        }, 3);
    }
}
