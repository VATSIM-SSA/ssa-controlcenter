<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove the Scandinavian positions upstream seeds.
 *
 * Upstream's `2020_03_08_171820_create_positions_table.php` does not only create
 * the table: it INSERTS Vatsim-Scandinavia's own positions, 402 of them across
 * seven FIRs (EKDK, EFIN, BIRD, BGGL, ENOR, ENOB, ESAA). On a fresh database
 * those sit alongside the 401 VATSSA positions loaded by
 * `2026_08_26_100000_vatssa_reference_data.php`, giving 803 in total and putting
 * EKCH_TWR in every position dropdown a VATSSA mentor sees.
 *
 * Found 2026-08-26 on the first real dev deploy: expected 401 positions across
 * 27 FIRs, got 803 across 34.
 *
 * Deliberately keyed on FIR rather than on a list of callsigns, so a position
 * upstream adds in a future release is removed too without this file changing.
 *
 * PRODUCTION: expected to be a no-op. `cc_prod` ran upstream's migration years
 * ago and those rows were dealt with then, so this should report 0. If it
 * reports otherwise during the cutover, stop and look — it means production is
 * carrying rows nobody knew about.
 *
 * If a foreign key blocks a delete, that is a real finding rather than an
 * inconvenience: something in this division references a Scandinavian position.
 * Let it fail loudly.
 */
return new class extends Migration
{
    /** The FIRs VATSSA actually operates. Must match FIR_AREA in the reference migration. */
    private const VATSSA_FIRS = [
        'AFRC', 'AFRS', 'AFRW',
        'DGAC', 'DNKK',
        'FAJO', 'FASA', 'FBGR', 'FCCC', 'FIMM', 'FLFI', 'FMMM', 'FNAN',
        'FQBE', 'FSSS', 'FVHF', 'FWLL', 'FYWF', 'FZZA',
        'GLRB', 'GOOO', 'GVSC',
        'HBBA', 'HKNA', 'HRYR', 'HTDC', 'HUEC',
    ];

    public function up(): void
    {
        $doomed = DB::table('positions')->whereNotIn('fir', self::VATSSA_FIRS);

        $firs = (clone $doomed)->distinct()->pluck('fir')->sort()->implode(', ');
        $count = (clone $doomed)->count();

        if ($count === 0) {
            return;
        }

        echo "  Removing {$count} non-VATSSA position(s) in FIR(s): {$firs}\n";

        $doomed->delete();
    }

    /**
     * Not reversible. Re-running upstream's seed data is not something this
     * fork wants, and the rows carry no VATSSA meaning.
     */
    public function down(): void
    {
        // no-op
    }
};
