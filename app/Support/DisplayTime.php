<?php

namespace App\Support;

use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Facades\Auth;

/**
 * The time zone the signed-in user reads and types times in.
 *
 * Everything is stored and exchanged in UTC (Zulu). A user may choose a local time zone in
 * their settings; pages then show times in it and the booking forms take times in it. With
 * no choice, no user (console, queue, e-mail) or an unknown zone, it is Zulu.
 */
class DisplayTime
{
    public const ZULU = 'UTC';

    public static function zone(): string
    {
        $zone = Auth::user()?->setting_timezone;

        return self::isValid($zone) ? $zone : self::ZULU;
    }

    public static function isZulu(): bool
    {
        return self::zone() === self::ZULU;
    }

    public static function isValid(?string $zone): bool
    {
        return filled($zone) && in_array($zone, DateTimeZone::listIdentifiers(), true);
    }

    /**
     * Convert a moment to the user's time zone.
     */
    public static function convert(CarbonInterface $date): CarbonInterface
    {
        return $date->copy()->setTimezone(self::zone());
    }

    /**
     * What follows a time: "z" for Zulu, or the zone's abbreviation, like " SAST".
     */
    public static function suffix(CarbonInterface $date): string
    {
        return self::isZulu() ? 'z' : ' ' . $date->copy()->setTimezone(self::zone())->format('T');
    }

    /**
     * How form labels name the zone times are typed in, like "Zulu" or "local, SAST".
     */
    public static function label(): string
    {
        return self::isZulu() ? 'Zulu' : 'local, ' . now(self::zone())->format('T');
    }
}
