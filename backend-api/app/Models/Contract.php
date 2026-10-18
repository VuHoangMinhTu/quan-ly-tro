<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contract extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'room_id',
        'tenant_id',
        'contract_code',
        'start_date',
        'end_date',
        'signed_date',
        'monthly_rent',
        'deposit_amount',
        'status',
        'note',
        'lessor_representative_user_id',
        'payment_due_day',
        'occupant_tenant_ids',
        'contract_template_id',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'signed_date' => 'date:Y-m-d',
        'monthly_rent' => 'decimal:2',
        'deposit_amount' => 'decimal:2',
        'occupant_tenant_ids' => 'array',
        'signing_snapshot' => 'array',
        'finalized_at' => 'datetime',
        'service_terms_version' => 'integer',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function occupants(): HasMany
    {
        return $this->hasMany(RoomTenant::class);
    }

    public function agreedServices(): HasMany
    {
        return $this->hasMany(ContractService::class);
    }

    public function utilityOpenings(): HasMany
    {
        return $this->hasMany(ContractUtilityOpening::class);
    }

    public function residenceDeclarations(): HasMany
    {
        return $this->hasMany(ResidenceDeclaration::class);
    }

    public function contractTemplate(): BelongsTo
    {
        return $this->belongsTo(ContractTemplate::class);
    }

    public function document(): HasOne
    {
        return $this->hasOne(ContractDocument::class);
    }
}
