<?php

use Database\Seeders\StandardContractTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        app(StandardContractTemplateSeeder::class)->run();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Contract documents may depend on this version. Never erase it during rollback.
    }
};
