<?php

namespace App\Console\Commands;

use App\Models\RoleAssignment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// VATSSA: read-only. Who VATSIM says has left the division while CC still has them, and
// what upstream's 05:00 clean-up would do to them once VATSSA_VATSIM_WRITE_USERS is on.
class VatssaVatsimLeavers extends Command
{
    protected $signature = 'vatssa:vatsim-leavers';

    protected $description = 'Preview members VATSIM says have left the division';

    public function handle(): int
    {
        $owner = config('app.owner_code');
        $rows = DB::table('users as u')->join('vatssa_vatsim_members as v', 'v.cid', '=', 'u.id')
            ->where('u.division', $owner)->whereNotNull('v.division')->where('v.division', '!=', $owner)->where('v.needs_lookup', false)
            ->orderBy('u.id')->get(['u.id', 'v.division']);

        $open = DB::table('trainings')->whereIn('user_id', $rows->pluck('id'))->where('status', '>=', 0)->where('type', '!=', 5)
            ->selectRaw('user_id, count(*) as total')->groupBy('user_id')->pluck('total', 'user_id');
        $mentors = RoleAssignment::whereIn('user_id', $rows->pluck('id'))->where('role', 'mentor')->distinct()->pluck('user_id')->flip();

        $this->table(['CID', 'Now in', 'Open trainings closed', 'Loses mentor role'], $rows->map(fn ($row) => [
            $row->id, $row->division, $open->get($row->id, 0), $mentors->has($row->id) ? 'unless visiting' : 'no',
        ])->all());

        $waiting = DB::table('vatssa_vatsim_members as v')->join('users as u', 'u.id', '=', 'v.cid')
            ->where('u.division', $owner)->where('v.needs_lookup', true)->count();
        $this->info($rows->count() . ' confirmed, ' . $waiting . ' still waiting for a VATSIM check.');
        $this->line('Writing to users is ' . (config('vatssa.vatsim_write_users') ? 'ON' : 'OFF') . '.');

        return self::SUCCESS;
    }
}
