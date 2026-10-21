<?php

namespace App\Models;

use Database\Factories\BoardingHouseResidenceProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoardingHouseResidenceProfile extends Model
{
    /** @use HasFactory<BoardingHouseResidenceProfileFactory> */
    use HasFactory;

    protected $fillable = ['household_head_name', 'household_head_identity_number', 'residence_authority_name', 'document_place', 'legal_owner_name', 'legal_owner_birth_year', 'legal_owner_permanent_address', 'legal_owner_identity_number', 'legal_owner_phone'];

    public function boardingHouse(): BelongsTo
    {
        return $this->belongsTo(BoardingHouse::class);
    }
}
