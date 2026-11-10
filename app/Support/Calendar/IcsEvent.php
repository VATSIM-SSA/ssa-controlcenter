<?php

namespace App\Support\Calendar;

use Carbon\CarbonInterface;

/**
 * A single event in an iCalendar feed.
 */
final readonly class IcsEvent
{
    public function __construct(
        public string $uid,
        public string $summary,
        public CarbonInterface $startsAt,
        public CarbonInterface $endsAt,
        public string $description = '',
        public string $location = '',
        public string $url = '',
    ) {}
}
