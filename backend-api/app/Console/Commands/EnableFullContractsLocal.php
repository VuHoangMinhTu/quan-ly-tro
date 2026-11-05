<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Explicit local-development opt-in. Never expose this operation through HTTP.
 */
class EnableFullContractsLocal extends Command
{
    protected $signature = 'beehouse:enable-full-contracts-local
                            {email : Email of an existing local landlord account}
                            {--apply : Confirm changing only this user contract_access from DEMO to FULL}';

    protected $description = 'Allow an existing local development landlord to test official contract templates';

    public function handle(): int
    {
        if (! app()->environment('local') || ! config('app.debug')) {
            $this->error('Refused: command is only available with APP_ENV=local and APP_DEBUG=true.');

            return self::FAILURE;
        }

        $connection = config('database.default');
        $database = config("database.connections.{$connection}", []);
        $driver = $database['driver'] ?? $connection;
        $host = strtolower((string) ($database['host'] ?? ''));
        if ($driver !== 'sqlite' && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            $this->error('Refused: database connection must use a local host or SQLite.');

            return self::FAILURE;
        }

        $email = trim((string) $this->argument('email'));
        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            $this->error('Account not found. No changes made.');

            return self::FAILURE;
        }
        if (! $user->landlord()->exists()) {
            $this->error('Account is not a landlord. No changes made.');

            return self::FAILURE;
        }

        $this->line('Account: '.$user->email);
        $this->line('Current contract access: '.($user->contract_access ?? 'null'));
        if ($user->contract_access === 'FULL') {
            $this->info('Already FULL; no changes made.');

            return self::SUCCESS;
        }
        if ($user->contract_access !== 'DEMO') {
            $this->error('Unexpected contract access value. No changes made.');

            return self::FAILURE;
        }
        if (! $this->option('apply')) {
            $this->warn('Dry run only. Add --apply to upgrade this local account.');

            return self::SUCCESS;
        }

        $user->forceFill(['contract_access' => 'FULL'])->save();
        $this->info('Local account upgraded to FULL. Existing DEMO contracts remain drafts until you select a STANDARD template and activate them through the normal UI.');

        return self::SUCCESS;
    }
}
