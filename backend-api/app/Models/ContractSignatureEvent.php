<?php

namespace App\Models;

use Database\Factories\ContractSignatureEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContractSignatureEvent extends Model
{
    /** @use HasFactory<ContractSignatureEventFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['contract_document_id', 'signing_request_id', 'actor_type', 'actor_user_id', 'actor_tenant_id', 'event', 'ip_address', 'user_agent', 'metadata', 'created_at'];

    protected $casts = ['metadata' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Signature events are append-only.'));
        static::deleting(fn () => throw new \LogicException('Signature events are append-only.'));
    }
}
