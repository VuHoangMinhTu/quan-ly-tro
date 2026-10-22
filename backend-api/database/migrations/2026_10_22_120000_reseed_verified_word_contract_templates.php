<?php

use Database\Seeders\DormBedContractTemplateSeeder;
use Database\Seeders\StandardContractTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Create new global versions; documents already finalized retain their original template snapshot.
        app(StandardContractTemplateSeeder::class)->run();
        app(DormBedContractTemplateSeeder::class)->run();
    }

    public function down(): void
    {
        // Do not delete template versions referenced by contracts or finalized documents.
    }
};
