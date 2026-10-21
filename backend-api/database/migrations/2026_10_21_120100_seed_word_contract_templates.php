<?php

use Database\Seeders\DormBedContractTemplateSeeder;
use Database\Seeders\StandardContractTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(StandardContractTemplateSeeder::class)->run();
        app(DormBedContractTemplateSeeder::class)->run();
    }

    public function down(): void
    {
        // Finalized documents reference immutable template versions.
    }
};
