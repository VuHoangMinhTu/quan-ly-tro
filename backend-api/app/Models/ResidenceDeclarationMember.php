<?php

namespace App\Models;

use Database\Factories\ResidenceDeclarationMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResidenceDeclarationMember extends Model
{
    /** @use HasFactory<ResidenceDeclarationMemberFactory> */
    use HasFactory;

    protected $fillable = ['tenant_id', 'relationship_to_household_head'];

    public function declaration(): BelongsTo
    {
        return $this->belongsTo(ResidenceDeclaration::class, 'residence_declaration_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
