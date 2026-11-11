<?php

namespace App\Support\Calendar;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Renders events as an RFC 5545 iCalendar document.
 *
 * All times are written in UTC; calendar clients convert them to the
 * viewer's local time zone.
 */
class IcsCalendar
{
    /**
     * @param  iterable<int, IcsEvent>  $events
     */
    public static function render(string $name, iterable $events): string
    {
        $dtStamp = self::formatUtc(Carbon::now());

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//' . self::escapeText((string) config('app.owner_name')) . '//Control Center//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::escapeText($name),
            'X-WR-TIMEZONE:UTC',
            'X-PUBLISHED-TTL:PT1H',
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
        ];

        foreach ($events as $event) {
            array_push($lines, ...self::eventLines($event, $dtStamp));
        }

        $lines[] = 'END:VCALENDAR';

        return self::foldLines($lines);
    }

    /**
     * @return array<int, string>
     */
    private static function eventLines(IcsEvent $event, string $dtStamp): array
    {
        $lines = [
            'BEGIN:VEVENT',
            'UID:' . $event->uid,
            'DTSTAMP:' . $dtStamp,
            'DTSTART:' . self::formatUtc($event->startsAt),
            'DTEND:' . self::formatUtc($event->endsAt),
            'SUMMARY:' . self::escapeText($event->summary),
        ];

        if ($event->description !== '') {
            $lines[] = 'DESCRIPTION:' . self::escapeText($event->description);
        }

        if ($event->location !== '') {
            $lines[] = 'LOCATION:' . self::escapeText($event->location);
        }

        if ($event->url !== '') {
            // A URI value isn't TEXT-escaped, but must stay on one line.
            $lines[] = 'URL:' . str_replace(["\r", "\n"], '', $event->url);
        }

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    private static function formatUtc(CarbonInterface $date): string
    {
        return $date->copy()->setTimezone('UTC')->format('Ymd\THis\Z');
    }

    /**
     * Escape a TEXT value (RFC 5545 section 3.3.11).
     */
    private static function escapeText(string $text): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\r", "\n"],
            ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'],
            $text,
        );
    }

    /**
     * Fold content lines to 75 octets (RFC 5545 section 3.1), never splitting
     * a multi-byte character across two physical lines.
     *
     * @param  array<int, string>  $lines
     */
    private static function foldLines(array $lines): string
    {
        $folded = [];

        foreach ($lines as $line) {
            $prefix = '';
            $limit = 75;

            while (strlen($line) > $limit) {
                $chunk = mb_strcut($line, 0, $limit, 'UTF-8');
                $folded[] = $prefix . $chunk;
                $line = substr($line, strlen($chunk));
                $prefix = ' ';
                $limit = 74;
            }

            $folded[] = $prefix . $line;
        }

        return implode("\r\n", $folded) . "\r\n";
    }
}
