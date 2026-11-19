<?php

namespace App\Services;

use App\Helpers\LogName;
use App\Helpers\TrainingStatus;
use App\Http\Controllers\TrainingActivityController;
use App\Models\Training;
use Illuminate\Support\Facades\DB;

/**
 * VATSSA: the staff flags on a training, "Requires attention" and "Stalled".
 *
 * Each flag is a nullable timestamp on `trainings` (when it was set), and
 * `trainings.flags_version` counts every change to either. All changes happen
 * under the training's row lock and bump the counter; a flag form carries the
 * counter it was rendered with (read from the same row as the flag state), so
 * a change from an older page, a retried request or a stale tab is refused
 * instead of undoing someone else's later change.
 *
 * Flags exist only on open trainings: they cannot be set on a closed one, a
 * close clears them, and a reopen clears anything a failed close left behind.
 * Every change is written to the timeline as a staff comment and to the
 * activity log.
 */
class VatssaTrainingFlags
{
    public const CHANGED = 'changed';

    public const UNCHANGED = 'unchanged';

    public const CLOSED = 'closed';

    public const STALE = 'stale';

    /**
     * Flag key (route segment and form field) => column and timeline wording.
     *
     * @var array<string, array{column: string, on: string, off: string, closed: string, reopened: string}>
     */
    public const FLAGS = [
        'attention' => [
            'column' => 'attention_at',
            'on' => 'Training marked as requiring attention.',
            'off' => 'Training no longer marked as requiring attention.',
            'closed' => 'Requires attention cleared automatically: the training was closed.',
            'reopened' => 'Requires attention cleared automatically: it was left from before the training was reopened.',
        ],
        'stalled' => [
            'column' => 'stalled_at',
            'on' => 'Training marked as stalled.',
            'off' => 'Training no longer marked as stalled.',
            'closed' => 'Stalled cleared automatically: the training was closed.',
            'reopened' => 'Stalled cleared automatically: it was left from before the training was reopened.',
        ],
    ];

    /**
     * Set or clear one flag by hand.
     *
     * @param  int  $seenVersion  flags_version the form was rendered with
     * @return string one of CHANGED, UNCHANGED (already in that state), CLOSED (training no
     *                longer open) or STALE (the flags changed since the form was rendered)
     */
    public function set(Training $training, string $flag, bool $state, ?int $userId, int $seenVersion): string
    {
        $config = self::FLAGS[$flag];

        return DB::transaction(function () use ($training, $config, $state, $userId, $seenVersion) {
            $current = Training::whereKey($training->getKey())->lockForUpdate()->firstOrFail();

            if (! $current->status->isOpen()) {
                return self::CLOSED;
            }

            if (($current->{$config['column']} !== null) === $state) {
                return self::UNCHANGED;
            }

            if ((int) $current->flags_version !== $seenVersion) {
                return self::STALE;
            }

            $current->{$config['column']} = $state ? now() : null;
            $current->flags_version = (int) $current->flags_version + 1;
            $current->save();

            $this->record($current, $state ? $config['on'] : $config['off'], $userId);

            return self::CHANGED;
        });
    }

    /**
     * After a close: clear every flag still set, with a timeline entry each.
     *
     * Runs after the close is saved, under the row lock, reading the row rather
     * than the model (which may have been loaded before a flag was set). Does
     * nothing if the training is open again by then: a reopen clears for itself.
     */
    public function clearAfterClose(Training $training): void
    {
        DB::transaction(function () use ($training) {
            $columns = array_merge(['status', 'flags_version'], array_column(self::FLAGS, 'column'));
            $row = DB::table($training->getTable())->where('id', $training->getKey())->lockForUpdate()->first($columns);
            if ($row === null || TrainingStatus::from((int) $row->status)->isOpen()) {
                return;
            }

            $set = array_filter(self::FLAGS, fn (array $config) => $row->{$config['column']} !== null);
            if ($set === []) {
                return;
            }

            $changes = array_fill_keys(array_column($set, 'column'), null);
            $changes['flags_version'] = (int) $row->flags_version + 1;
            DB::table($training->getTable())->where('id', $training->getKey())->update($changes);
            $training->forceFill($changes)->syncOriginalAttributes(array_keys($changes));

            foreach ($set as $config) {
                $this->record($training, $config['closed'], null);
            }
        });
    }

    /**
     * Before a reopen is saved: clear any flag left from before (only if a
     * close's own clearing failed), in the same UPDATE as the reopen.
     *
     * Safe without timestamps: while the training is closed no flag can be
     * set (set() refuses under the lock until the reopen is committed), so any
     * flag in the row now is from before. Reads the row, not the model, and
     * marks each column dirty from its database value so the reopen UPDATE
     * writes the clear even if the model was loaded without the flag.
     *
     * @return array<int, string> timeline texts to record once the reopen is saved
     */
    public function clearBeforeReopen(Training $training): array
    {
        $columns = array_merge(['flags_version'], array_column(self::FLAGS, 'column'));
        $row = DB::table($training->getTable())->where('id', $training->getKey())->first($columns);
        if ($row === null) {
            return [];
        }

        $left = array_filter(self::FLAGS, fn (array $config) => $row->{$config['column']} !== null);
        if ($left === []) {
            return [];
        }

        foreach ($left as $config) {
            $training->setAttribute($config['column'], $row->{$config['column']});
            $training->syncOriginalAttribute($config['column']);
            $training->setAttribute($config['column'], null);
        }
        $training->setAttribute('flags_version', (int) $row->flags_version + 1);

        return array_column($left, 'reopened');
    }

    /**
     * Write timeline entries for flags a reopen cleared.
     *
     * @param  array<int, string>  $texts
     */
    public function recordReopenClears(Training $training, array $texts): void
    {
        DB::transaction(function () use ($training, $texts) {
            foreach ($texts as $text) {
                $this->record($training, $text, null);
            }
        });
    }

    private function record(Training $training, string $text, ?int $userId): void
    {
        ActivityLogService::warning(LogName::Training, rtrim($text, '.') . ' on training ' . $training->id);
        TrainingActivityController::create($training->id, 'COMMENT', null, null, $userId, $text);
    }
}
