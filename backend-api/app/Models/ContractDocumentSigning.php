<?php

namespace App\Models;

use Database\Factories\ContractDocumentSigningFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContractDocumentSigning extends Model
{
    /** @use HasFactory<ContractDocumentSigningFactory> */
    use HasFactory;

    protected $fillable = ['contract_document_id', 'status', 'signatures_completed_at', 'signed_rendered_html', 'signed_document_hash', 'voided_at', 'void_reason'];

    protected $casts = ['signatures_completed_at' => 'datetime', 'voided_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if ($record->getOriginal('signed_document_hash') !== null && $record->isDirty(['signed_rendered_html', 'signed_document_hash', 'signatures_completed_at'])) {
                throw new \LogicException('Completed signed evidence is immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Signing history must be preserved.'));
    }
}
