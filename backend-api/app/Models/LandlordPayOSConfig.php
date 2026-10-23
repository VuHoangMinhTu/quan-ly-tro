<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LandlordPayOSConfig extends Model
{
    protected $table = 'landlord_payos_configs';

    protected $fillable = ['landlord_id', 'client_id', 'api_key', 'checksum_key', 'webhook_uuid', 'is_active'];

    protected $hidden = ['api_key', 'checksum_key'];

    protected $casts = ['api_key' => 'encrypted', 'checksum_key' => 'encrypted', 'is_active' => 'boolean'];
}
