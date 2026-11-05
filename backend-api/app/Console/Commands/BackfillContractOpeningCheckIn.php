<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Services\ContractOpeningCheckIn;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class BackfillContractOpeningCheckIn extends Command
{
    protected $signature = 'contracts:sync-opening-checkin {contract_id : ID hop dong ACTIVE} {--apply : Thuc su ghi mốc sau khi doi soat}';
    protected $description = 'Doi soat moc CHECK_IN V2 tu chi so dau hop dong; mac dinh chi xem, khong ghi DB.';

    public function handle(ContractOpeningCheckIn $sync): int
    {
        $contract = Contract::query()->with(['utilityOpenings', 'agreedServices'])->findOrFail((int) $this->argument('contract_id'));
        if ($contract->status !== 'ACTIVE' || $contract->is_demo) {
            $this->error('Chi ho tro hop dong ACTIVE thuc te.');
            return self::FAILURE;
        }
        $ownerId = $contract->room->boardingHouse->landlord->user_id;
        $this->info('Contract #'.$contract->id.'; date '.($contract->start_date?->format('Y-m-d') ?? 'null'));
        foreach ($contract->utilityOpenings as $opening) {
            $this->line('Meter #'.$opening->utility_meter_id.'; service #'.$opening->service_id.'; opening '.$opening->opening_reading.'; date '.$opening->reading_date?->format('Y-m-d'));
        }
        if (! $this->option('apply')) {
            $this->warn('DRY RUN: khong ghi CSDL. Neu thong tin dung, chay lai voi --apply.');
            return self::SUCCESS;
        }
        try {
            $result = DB::transaction(function () use ($contract, $sync, $ownerId) {
                \App\Models\Room::query()->lockForUpdate()->findOrFail($contract->room_id);
                $locked = Contract::query()->lockForUpdate()->findOrFail($contract->id);
                return $sync->sync($locked, (int) $ownerId);
            }, 3);
            $this->info('SYNC OK: '.json_encode($result));
            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error('SYNC BLOCKED: '.$error->getMessage());
            return self::FAILURE;
        }
    }
}
