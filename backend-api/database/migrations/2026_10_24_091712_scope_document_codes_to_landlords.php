<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['contracts' => 'contract_code', 'invoices' => 'invoice_code'] as $table => $code) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreignId('landlord_id')->nullable()->constrained('landlords')->restrictOnDelete());
            DB::table($table)->orderBy('id')->chunkById(500, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    $landlord = DB::table('rooms')->join('boarding_houses', 'rooms.boarding_house_id', '=', 'boarding_houses.id')
                        ->where('rooms.id', $row->room_id)->value('boarding_houses.landlord_id');
                    if ($landlord === null) {
                        throw new RuntimeException("Cannot resolve landlord for {$table} #{$row->id}.");
                    }
                    DB::table($table)->where('id', $row->id)->update(['landlord_id' => $landlord]);
                }
            });
            Schema::table($table, function (Blueprint $blueprint) use ($code): void {
                $blueprint->unsignedBigInteger('landlord_id')->nullable(false)->change();
                $blueprint->unique(['landlord_id', $code]);
                $blueprint->dropUnique([$code]);
            });
        }
        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('landlord_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 16);
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['landlord_id', 'document_type', 'year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Forward fix required: global uniqueness cannot be restored without changing business codes.');
    }
};
