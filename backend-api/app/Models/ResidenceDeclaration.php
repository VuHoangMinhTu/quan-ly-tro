<?php

namespace App\Models;

use Database\Factories\ResidenceDeclarationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResidenceDeclaration extends Model
{
    /** @use HasFactory<ResidenceDeclarationFactory> */
    use HasFactory;

    public const STATUSES = ['DRAFT', 'PENDING', 'SUBMITTED', 'REGISTERED', 'REJECTED', 'EXPIRED'];

    protected $fillable = ['landlord_id', 'boarding_house_id', 'room_id', 'contract_id', 'declarant_tenant_id', 'authority_name', 'relationship_to_household_head', 'request_content', 'stay_start_date', 'stay_end_date', 'household_head_opinion', 'legal_owner_opinion', 'guardian_opinion', 'status', 'submitted_at', 'registered_at', 'expires_at', 'snapshot'];

    protected $casts = ['snapshot' => 'array', 'submitted_at' => 'datetime', 'registered_at' => 'datetime', 'expires_at' => 'date:Y-m-d', 'stay_start_date' => 'date:Y-m-d', 'stay_end_date' => 'date:Y-m-d'];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function boardingHouse(): BelongsTo
    {
        return $this->belongsTo(BoardingHouse::class);
    }

    public function landlord(): BelongsTo
    {
        return $this->belongsTo(Landlord::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function declarant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'declarant_tenant_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ResidenceDeclarationMember::class)->orderBy('id');
    }
}
