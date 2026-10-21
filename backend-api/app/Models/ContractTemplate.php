<?php

namespace App\Models;

use Database\Factories\ContractTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class ContractTemplate extends Model
{
    /** @use HasFactory<ContractTemplateFactory> */
    use HasFactory;

    protected $fillable = ['landlord_id', 'scope_key', 'template_key', 'name', 'version', 'content_html', 'is_active', 'created_by_user_id'];

    protected $casts = ['is_active' => 'boolean', 'version' => 'integer'];

    public function documents(): HasMany
    {
        return $this->hasMany(ContractDocument::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'contract_template_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $template): void {
            if ($template->isDirty(['content_html', 'template_key', 'version', 'landlord_id', 'scope_key', 'name']) && $template->documents()->exists()) {
                throw ValidationException::withMessages(['content_html' => 'Mẫu này đã được sử dụng. Hãy tạo phiên bản mới để chỉnh sửa.']);
            }
        });
        static::deleting(function (self $template): void {
            if ($template->landlord_id === null || $template->documents()->exists() || $template->contracts()->exists()) {
                throw ValidationException::withMessages(['template' => 'Mẫu BeeHouse hoặc mẫu đã được sử dụng không thể xóa.']);
            }
        });
    }
}
