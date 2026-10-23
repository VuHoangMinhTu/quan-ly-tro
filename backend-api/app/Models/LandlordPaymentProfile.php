<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LandlordPaymentProfile extends Model
{
    protected $fillable = ['landlord_id', 'bank_name', 'bank_account_name', 'bank_account_number'];
}
