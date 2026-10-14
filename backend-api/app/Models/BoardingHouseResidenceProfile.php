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

    protected $fillable = ['household_head_name', 'household_head_identity_number'];

    public function boardingHouse(): BelongsTo
    {
        return $this->belongsTo(BoardingHouse::class);
    }
}
