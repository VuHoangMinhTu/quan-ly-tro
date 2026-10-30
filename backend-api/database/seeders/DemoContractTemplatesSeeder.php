<?php

namespace Database\Seeders;

use App\Models\ContractTemplate;
use App\Services\ContractTemplateDemo;
use Illuminate\Database\Seeder;

class DemoContractTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $demo = app(ContractTemplateDemo::class);
        foreach (['room' => 'ROOM_RENTAL_DEMO', 'dorm' => 'DORM_BED_DEMO'] as $type => $key) {
            ContractTemplate::firstOrCreate(['scope_key' => 'GLOBAL', 'template_key' => $key, 'version' => 1], [
                'landlord_id' => null, 'name' => $demo->definitions()[$type]['name'],
                'content_html' => $demo->content($type), 'is_active' => true,
            ]);
        }
    }
}
