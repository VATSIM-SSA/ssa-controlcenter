<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * VATSSA: small values the Discord sync keeps between processes.
 *
 * Deliberately not the settings package: Setting::save() deletes any key that appeared
 * after that process loaded its settings, and the scheduler saves every minute.
 */
class VatssaDiscordState
{
    private const TABLE = 'vatssa_discord_state';

    public static function get(string $name, mixed $default = null): mixed
    {
        $value = DB::table(self::TABLE)->where('name', $name)->value('value');

        return $value === null ? $default : json_decode($value, true);
    }

    public static function put(string $name, mixed $value): void
    {
        DB::table(self::TABLE)->upsert([['name' => $name, 'value' => json_encode($value), 'updated_at' => now()]], ['name'], ['value', 'updated_at']);
    }

    /**
     * Read a value once: nobody else can read the same one.
     */
    public static function pull(string $name): mixed
    {
        return DB::transaction(function () use ($name) {
            $value = DB::table(self::TABLE)->where('name', $name)->lockForUpdate()->value('value');
            DB::table(self::TABLE)->where('name', $name)->delete();

            return $value === null ? null : json_decode($value, true);
        });
    }

    /**
     * Take one of `$limit` places. False when none are left.
     */
    public static function reserve(string $name, int $limit): bool
    {
        return DB::transaction(function () use ($name, $limit) {
            DB::table(self::TABLE)->insertOrIgnore(['name' => $name, 'value' => '0', 'updated_at' => now()]);
            $used = (int) DB::table(self::TABLE)->where('name', $name)->lockForUpdate()->value('value');
            if ($used >= $limit) {
                return false;
            }
            DB::table(self::TABLE)->where('name', $name)->update(['value' => (string) ($used + 1), 'updated_at' => now()]);

            return true;
        });
    }

    /**
     * Drop older values of a dated series, such as yesterday's limit counter.
     */
    public static function pruneSeries(string $prefix, string $keep): void
    {
        DB::table(self::TABLE)->where('name', 'like', $prefix . '%')->where('name', '!=', $keep)->delete();
    }
}
