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
        Schema::table('landlord_payment_profiles', fn (Blueprint $table) => $table->string('payment_qr_path')->nullable());
        Schema::table('invoices', fn (Blueprint $table) => $table->json('payment_snapshot')->nullable());
        // Legacy bills have no historical bank snapshot. Freeze the configuration
        // available at deployment rather than changing them on every later view.
        DB::table('invoices')->where(fn ($query) => $query->where('status', '!=', 'DRAFT')->orWhereNotNull('issued_at'))
            ->orderBy('id')->chunkById(500, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    $profile = DB::table('landlord_payment_profiles')->where('landlord_id', $invoice->landlord_id)->first();
                    DB::table('invoices')->where('id', $invoice->id)->update(['payment_snapshot' => json_encode([
                        'bank_name' => $profile?->bank_name, 'bank_account_name' => $profile?->bank_account_name,
                        'bank_account_number' => $profile?->bank_account_number, 'payment_qr_path' => $profile?->payment_qr_path,
                    ], JSON_THROW_ON_ERROR)]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Removing snapshots would make issued bills read a changed live profile.
        throw new RuntimeException('Forward fix required: invoice payment snapshots must be preserved.');
    }
};
