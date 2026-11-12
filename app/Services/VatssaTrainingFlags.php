<?php

namespace App\Services;

use App\Helpers\LogName;
use App\Http\Controllers\TrainingActivityController;
use App\Models\Training;
use Illuminate\Support\Facades\DB;

/**
 * VATSSA: the staff flags on a training, "Requires attention" and "Stalled".
 *
 * Each flag is a nullable timestamp on `trainings` (when it was set). Every
 * change, by hand or automatic, is written to the timeline as a staff comment
 * and to the activity log, in the same transaction as the change itself, so a
 * flag never changes without its history.
 *
 * Changes take a row lock on the training and re-read it, so two clicks at
 * once give one change and one comment, and a flag cannot be set on a
 * training that closed in the meantime.
 */
class VatssaTrainingFlags
{
    /**
     * Flag key (route segment and form field) => column and timeline wording.
     *
     * @var array<string, array{column: string, on: string, off: string, closed: string}>
     */
    public const FLAGS = [
        'attention' => [
            'column' => 'attention_at',
            'on' => 'Training marked as requiring attention.',
            'off' => 'Training no longer marked as requiring attention.',
            'closed' => 'Requires attention cleared automatically: the training was closed.',
        ],
        'stalled' => [
            'column' => 'stalled_at',
            'on' => 'Training marked as stalled.',
            'off' => 'Training no longer marked as stalled.',
            'closed' => 'Stalled cleared automatically: the training was closed.',
        ],
    ];

    /**
     * Set or clear one flag by hand.
     *
     * @return bool|null true if it changed, false if it already had that state,
     *                   null if the training is no longer open
     */
    public function set(Training $training, string $flag, bool $state, ?int $userId): ?bool
    {
        $config = self::FLAGS[$flag];

        return DB::transaction(function () use ($training, $config, $state, $userId) {
            $current = Training::whereKey($training->getKey())->lockForUpdate()->firstOrFail();

            if (! $current->status->isOpen()) {
                return null;
            }

            if (($current->{$config['column']} !== null) === $state) {
                return false;
            }

            $current->{$config['column']} = $state ? now() : null;
            $current->save();

            $this->record($current, $state ? $config['on'] : $config['off'], $userId);

            return true;
        });
    }

    /**
     * Clear every flag still set on a training that has just closed, with a
     * timeline entry for each. Reads the row from the database rather than
     * trusting the model in memory, which may have been loaded before a flag
     * was set.
     */
    public function clearOnClose(Training $training): void
    {
        DB::transaction(function () use ($training) {
            $columns = array_column(self::FLAGS, 'column');
            $row = DB::table($training->getTable())->where('id', $training->getKey())->lockForUpdate()->first($columns);
            if ($row === null) {
                return;
            }

            $cleared = array_filter(self::FLAGS, fn (array $config) => $row->{$config['column']} !== null);
            if ($cleared === []) {
                return;
            }

            $nulls = array_fill_keys(array_column($cleared, 'column'), null);
            DB::table($training->getTable())->where('id', $training->getKey())->update($nulls);
            $training->forceFill($nulls)->syncOriginalAttributes(array_keys($nulls));

            foreach ($cleared as $config) {
                $this->record($training, $config['closed'], null);
            }
        });
    }

    private function record(Training $training, string $text, ?int $userId): void
    {
        ActivityLogService::warning(LogName::Training, rtrim($text, '.') . ' on training ' . $training->id);
        TrainingActivityController::create($training->id, 'COMMENT', null, null, $userId, $text);
    }
}
