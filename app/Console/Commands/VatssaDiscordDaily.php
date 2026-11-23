<?php

namespace App\Console\Commands;

use App\Http\Controllers\VatssaDiscordSyncController;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// VATSSA: the daily round. Queues every CC user the queue is not already waiting on.
class VatssaDiscordDaily extends Command
{
    protected $signature = 'vatssa:discord-daily';

    protected $description = 'Queue every CC user for a Discord role sync';

    public function handle(): int
    {
        $waiting = DB::table('vatssa_discord_queue')->where('state', '!=', 'idle')->pluck('user_id')->flip();
        $ids = User::where(fn ($q) => $q->whereNull('email')->orWhere('email', '!=', VatssaDiscordSyncController::PSEUDONYMISED_EMAIL))->pluck('id')
            ->reject(fn ($id) => $waiting->has($id))->map(fn ($id) => (int) $id)->values()->all();

        VatssaDiscordSyncController::queue($ids, 'daily');
        $this->info(count($ids) . ' queued');

        return self::SUCCESS;
    }
}
