<?php

namespace App\Models;

use Database\Factories\ContractDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractDocument extends Model
{
    /** @use HasFactory<ContractDocumentFactory> */
    use HasFactory;

    protected $fillable = ['contract_id', 'contract_template_id', 'template_key', 'template_version', 'template_name', 'rendered_html', 'source_snapshot', 'document_hash', 'finalized_at'];

    protected $casts = ['source_snapshot' => 'array', 'finalized_at' => 'datetime'];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Finalized contract documents are immutable.'));
        static::deleting(fn () => throw new \LogicException('Finalized contract documents must be preserved.'));
    }
}
