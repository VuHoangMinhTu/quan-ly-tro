<?php

use App\Models\TenantImport;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('tenant-imports:prune', function (): void {
    $deleted = TenantImport::query()->where('expires_at', '<=', now())->delete();
    $this->info("Đã dọn {$deleted} phiên import hết hạn.");
})->purpose('Xóa dữ liệu preview người thuê đã hết hạn');

Schedule::command('tenant-imports:prune')->everyTenMinutes()->withoutOverlapping();
