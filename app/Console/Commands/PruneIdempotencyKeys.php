<?php

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Not scheduled on purpose: expired keys are handled on use (frozen MVP decision).
 * Run by hand, or add `Schedule::command('idempotency:prune')->daily();` in
 * routes/console.php if table growth ever needs it.
 */
final class PruneIdempotencyKeys extends Command
{
    protected $signature = 'idempotency:prune {--dry-run : Only report counts, delete nothing}';

    protected $description = 'Delete expired idempotency keys. Active keys are never touched.';

    public function handle(): int
    {
        $table = (new IdempotencyKey)->getTable();
        $now = now();

        $total = DB::table($table)->count();
        $expired = DB::table($table)->where('expires_at', '<', $now)->count();

        $this->line(sprintf('idempotency keys: total=%d active=%d expired=%d', $total, $total - $expired, $expired));

        if ($this->option('dry-run') || $expired === 0) {
            return self::SUCCESS;
        }

        $deleted = DB::table($table)->where('expires_at', '<', $now)->delete();
        $this->info("Pruned {$deleted} expired idempotency key(s).");

        return self::SUCCESS;
    }
}
