<?php

use Database\Seeders\StandardContractTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Publish the new Article 4 as a new template version; signed documents retain their snapshot.
        app(StandardContractTemplateSeeder::class)->run();
    }

    public function down(): void
    {
        // Do not remove template versions that may be referenced by contracts.
    }
};
