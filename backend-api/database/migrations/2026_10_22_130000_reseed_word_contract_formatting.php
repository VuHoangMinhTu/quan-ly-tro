<?php

use Database\Seeders\DormBedContractTemplateSeeder;
use Database\Seeders\StandardContractTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Publish corrected Word formatting as new versions; signed snapshots remain immutable.
        app(StandardContractTemplateSeeder::class)->run();
        app(DormBedContractTemplateSeeder::class)->run();
    }

    public function down(): void
    {
        // Never delete versions that may already be referenced by a contract.
    }
};
