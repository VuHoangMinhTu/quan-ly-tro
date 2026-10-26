<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantImport extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'user_id', 'landlord_id', 'payload', 'result', 'expires_at'];

    protected $hidden = ['payload'];

    protected $casts = ['payload' => 'encrypted:array', 'result' => 'array', 'expires_at' => 'datetime'];
}
