<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resets the demo data to the seed (plus any recorded runs) but keeps the cache table, where today's
 * spend, run count and per-visitor limits live. A plain migrate:fresh would zero them mid-day and
 * let the demo spend its daily limit twice.
 */
class ResetDemo extends Command
{
    protected $signature = 'triagedesk:reset-demo';

    protected $description = "Reset the demo data and keep today's spend and rate limits";

    public function handle(): int
    {
        $table = config('cache.stores.database.table', 'cache');
        $kept = Schema::hasTable($table)
            ? DB::table($table)->where('expiration', '>', now()->getTimestamp())->get()->map(fn ($row) => (array) $row)->all()
            : [];

        $this->call('migrate:fresh', ['--seed' => true, '--force' => true]);

        foreach (array_chunk($kept, 100) as $rows) {
            DB::table($table)->insertOrIgnore($rows);
        }

        $this->info('Demo data reset; kept '.count($kept).' cache entries (spend, run counts, rate limits).');

        return self::SUCCESS;
    }
}
