<?php

namespace App\Helpers;

use App\Traits\ComparableIntEnum;

/**
 * The VATSIM ATC ratings.
 *
 * The case name is the short code (e.g. `OBS`, `S3`) and the backing value is the numeric
 * VATSIM rating id.
 *
 * VATSSA: `C2` (6) and `I2` (9) are here even though VATSIM stopped issuing
 * them. Upstream leaves them out as "unused", but the rating comes off the
 * VATSIM member record at login and is cast straight into this enum -- so an
 * account that still carries one hits `ValueError: 9 is not a valid backing
 * value` in `LoginController::completeLogin()` and CANNOT LOG IN AT ALL. A
 * rating nobody is granted any more is still a rating somebody holds.
 */
enum VatsimRating: int
{
    use ComparableIntEnum;

    /** Inactive. */
    case INA = -1;

    /** Suspended. */
    case SUS = 0;

    /** Pilot/Observer. */
    case OBS = 1;

    /** Tower Trainee. */
    case S1 = 2;

    /** Tower Controller. */
    case S2 = 3;

    /** TMA Controller. */
    case S3 = 4;

    /** Enroute Controller. */
    case C1 = 5;

    /** Enroute Controller (retired grade, still held by older accounts). */
    case C2 = 6;

    /** Senior Controller. */
    case C3 = 7;

    /** Instructor. */
    case I1 = 8;

    /** Instructor (retired grade, still held by older accounts). */
    case I2 = 9;

    /** Senior Instructor. */
    case I3 = 10;

    /** Supervisor. */
    case SUP = 11;

    /** Administrator. */
    case ADM = 12;

    public const SPECIAL_RATINGS = self::NOT_POSITION_RATINGS;

    public const NOT_POSITION_RATINGS = [
        self::INA,
        self::SUS,
        self::OBS,
        self::SUP,
        self::ADM,
    ];

    public const CONTROLLER_RATINGS = [
        self::S1,
        self::S2,
        self::S3,
        self::C1,
        self::C2,
        self::C3,
        self::I1,
        self::I2,
        self::I3,
    ];

    // C2 and I2 are deliberately absent: they are recognised, not trained for.
    public const TRAINABLE_RATINGS = [
        self::S1,
        self::S2,
        self::S3,
        self::C1,
        self::C3,
    ];

    public static function getControllerRatings()
    {
        return collect(self::CONTROLLER_RATINGS)->mapWithKeys(function ($rating) {
            return [$rating->value => $rating];
        })->toArray();
    }

    /**
     * Check if a numeric value is a valid VatsimRating
     */
    public static function isValidValue(int $value): bool
    {
        try {
            self::from($value);

            return true;
        } catch (\ValueError $e) {
            return false;
        }
    }

    /**
     * Get all valid position rating values (for form dropdowns)
     */
    public static function getPositionRatingValues(): array
    {
        $validValues = [];

        foreach (self::cases() as $rating) {
            // Skip non-position ratings
            if (in_array($rating, self::NOT_POSITION_RATINGS)) {
                continue;
            }

            $validValues[] = $rating->value;
        }

        return $validValues;
    }
}
