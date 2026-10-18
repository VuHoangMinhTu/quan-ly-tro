<?php

namespace App\Models;

use Database\Factories\ContractSignatureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContractSignature extends Model
{
    /** @use HasFactory<ContractSignatureFactory> */
    use HasFactory;

    protected $fillable = ['contract_document_id', 'signing_request_id', 'signer_role', 'signer_user_id', 'signer_tenant_id', 'signer_name_snapshot', 'signature_method', 'verification_method', 'signature_file_path', 'signature_file_hash', 'document_hash', 'consent_version', 'consent_text', 'signed_at', 'ip_address', 'user_agent'];

    protected $hidden = ['signature_file_path', 'ip_address', 'user_agent'];

    protected $casts = ['signed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Signature evidence is immutable.'));
        static::deleting(fn () => throw new \LogicException('Signature evidence must be preserved.'));
    }
}
