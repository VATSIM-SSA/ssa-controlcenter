<?php

namespace App\Console\Commands;

use App\Services\VatssaVatsimFacts;
use Illuminate\Console\Command;

// VATSSA: the nightly division list, then whoever is waiting for an individual check.
class VatssaVatsimPull extends Command
{
    protected $signature = 'vatssa:vatsim-pull {--lookups : Only run the waiting individual checks}';

    protected $description = 'Refresh VATSIM rating and division for the Discord role sync';

    public function handle(VatssaVatsimFacts $facts): int
    {
        if ($this->option('lookups')) {
            $this->info($facts->runLookups() . ' checked');

            return self::SUCCESS;
        }

        try {
            $this->info($facts->pull() . ' members on the division list');
        } catch (\Throwable $e) {
            report($e);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
