<?php

namespace App\Models;

use Database\Factories\ContractSigningRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractSigningRequest extends Model
{
    /** @use HasFactory<ContractSigningRequestFactory> */
    use HasFactory;

    protected $fillable = ['contract_document_id', 'signer_role', 'active_key', 'signer_user_id', 'signer_tenant_id', 'signer_name_snapshot', 'delivery_channel', 'delivery_destination_snapshot', 'token_hash', 'token_expires_at', 'otp_hash', 'otp_expires_at', 'otp_attempts', 'otp_verified_at', 'verification_hash', 'last_otp_sent_at', 'sent_at', 'status', 'created_by_user_id', 'completed_at', 'revoked_at'];

    protected $hidden = ['token_hash', 'otp_hash', 'verification_hash', 'active_key', 'delivery_destination_snapshot'];

    protected $casts = ['token_expires_at' => 'datetime', 'otp_expires_at' => 'datetime', 'otp_verified_at' => 'datetime', 'last_otp_sent_at' => 'datetime', 'sent_at' => 'datetime', 'completed_at' => 'datetime', 'revoked_at' => 'datetime', 'otp_attempts' => 'integer'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(ContractDocument::class, 'contract_document_id');
    }
}
