<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE services MODIFY type ENUM('ELECTRICITY','WATER','INTERNET','PARKING','TRASH','CLEANING','SERVICE','OTHER') NOT NULL");
        } else {
            Schema::table('services', function (Blueprint $table): void {
                $table->enum('type', ['ELECTRICITY', 'WATER', 'INTERNET', 'PARKING', 'TRASH', 'CLEANING', 'SERVICE', 'OTHER'])->change();
            });
        }
    }

    public function down(): void
    {
        // Existing SERVICE rows must not be invalidated by rolling back this migration.
    }
};
