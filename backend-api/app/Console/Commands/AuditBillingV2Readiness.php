<?php

namespace App\Console\Commands;

use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Read-only local diagnostic; never changes contracts, meters, invoices or prices. */
class AuditBillingV2Readiness extends Command
{
    protected $signature = 'beehouse:audit-billing-v2 {roomId : Room database ID} {--month=2026-11 : Billing month YYYY-MM}';
    protected $description = 'Read-only diagnosis of contract service prices, meter checkpoints and CHECK_IN evidence.';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Only permitted in APP_ENV=local. No data changed.');
            return self::FAILURE;
        }

        $month = (string) $this->option('month');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $this->error('Use --month=YYYY-MM');
            return self::FAILURE;
        }

        $start = CarbonImmutable::parse($month . '-01')->startOfMonth();
        $end = $start->addMonth();
        $room = Room::query()->with(['utilityMeters.service', 'contracts.agreedServices'])->find((int) $this->argument('roomId'));
        if (! $room) {
            $this->error('Room not found. Check room database ID.');
            return self::FAILURE;
        }

        $this->info('READ ONLY / Room ID ' . $room->id . ' / code ' . $room->room_code . ' / invoice month ' . $month);
        $this->line('Date window for finding relevant contracts: ' . $start->toDateString() . ' through ' . $end->toDateString() . ' (exclusive)');
        $contracts = $room->contracts->filter(fn ($c) => in_array($c->status, ['ACTIVE', 'EXPIRED', 'TERMINATED'], true)
            && $c->start_date && $c->start_date->toDateString() < $end->toDateString()
            && (! $c->end_date || $c->end_date->toDateString() >= $start->toDateString()))->values();
        if ($contracts->isEmpty()) {
            $this->warn('No ACTIVE/EXPIRED/TERMINATED contract overlapping the selected month.');
        }
        $this->table(['Contract ID', 'Code', 'Status', 'Start', 'End', 'Terms version'],
            $contracts->map(fn ($c) => [$c->id, $c->contract_code, $c->status, $c->start_date?->format('Y-m-d'), $c->end_date?->format('Y-m-d') ?? '-', $c->service_terms_version ?? 'NULL'])->all());

        $meters = $room->utilityMeters->filter(fn ($m) => in_array($m->service?->type, ['ELECTRICITY', 'WATER'], true));
        if ($meters->isEmpty()) {
            $this->warn('No ELECTRICITY/WATER meters associated with this room.');
        }
        foreach ($meters as $meter) {
            $service = $meter->service;
            $this->newLine();
            $this->info('Meter #' . $meter->id . ' / ' . $service->type . ' / service_id=' . $meter->service_id . ' / service=' . $service->name);
            $checkpoints = DB::table('utility_checkpoints')->where('utility_meter_id', $meter->id)
                ->where('observed_at', '>=', $start->subMonth()->format('Y-m-d H:i:s'))
                ->where('observed_at', '<=', $end->addMonth()->format('Y-m-d H:i:s'))
                ->orderBy('observed_at')->orderBy('revision')->get(['kind', 'contract_id', 'observed_at', 'reading_value', 'revision', 'scope_key']);
            $this->table(['Kind', 'Contract ID', 'Time', 'Value', 'Rev'],
                $checkpoints->map(fn ($p) => [$p->kind, $p->contract_id ?? '-', $p->observed_at, $p->reading_value, $p->revision])->all());
            foreach ($contracts as $contract) {
                $terms = $contract->agreedServices->where('service_id', $meter->service_id)->values();
                $this->line('Contract #' . $contract->id . ' matching price rows: ' . $terms->count());
                if ($terms->isEmpty()) {
                    $this->error('MISSING: contract service_id=' . $meter->service_id . ' not found in this contract.');
                    $this->comment('Check contract Services and its agreed price snapshot, NOT the current service price alone.');
                }
                foreach ($terms as $term) {
                    $this->line(sprintf('  term #%s active=%s period=%s -> %s method=%s price=%s',
                        $term->id, $term->is_active ? 'yes' : 'no', $term->effective_from?->format('Y-m-d') ?? 'NULL',
                        $term->effective_to?->format('Y-m-d') ?? 'open', $term->billing_method, $term->base_price ?? 'tiered'));
                    if (! $term->is_active || ! $term->effective_from || $term->effective_from->toDateString() >= $end->toDateString()
                        || ($term->effective_to && $term->effective_to->toDateString() < $start->toDateString())) {
                        $this->warn('  Term may not apply in selected month. Check exact meter cycle dates too.');
                    }
                }
                $hasIn = DB::table('utility_checkpoints')->where('utility_meter_id', $meter->id)
                    ->where('contract_id', $contract->id)->where('kind', 'CHECK_IN')->exists();
                $this->line('  CHECK_IN present: ' . ($hasIn ? 'yes' : 'NO'));
            }
        }

        $this->newLine();
        $this->info('Audit completed. No database rows changed. Do not paste DB secrets into chat.');
        return self::SUCCESS;
    }
}
