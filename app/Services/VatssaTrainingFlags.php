<?php

namespace App\Services;

use App\Helpers\LogName;
use App\Helpers\TrainingStatus;
use App\Http\Controllers\TrainingActivityController;
use App\Models\Training;
use App\Models\TrainingActivity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * VATSSA: the staff flags on a training, "Requires attention" and "Stalled".
 *
 * Each flag is a nullable timestamp on `trainings` (when it was set). Every
 * change, by hand or automatic, is written to the timeline as a staff comment
 * and to the activity log, in the same transaction as the change itself, so a
 * flag never changes without its history.
 *
 * Every change takes a row lock on the training and re-reads it under the
 * lock: two clicks at once give one change, a flag cannot be set on a training
 * that closed in the meantime, and a form older than the training's timeline
 * is refused rather than undoing someone else's later change.
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
            'reopened' => 'Requires attention cleared automatically: it was set before the training was reopened.',
        ],
        'stalled' => [
            'column' => 'stalled_at',
            'on' => 'Training marked as stalled.',
            'off' => 'Training no longer marked as stalled.',
            'closed' => 'Stalled cleared automatically: the training was closed.',
            'reopened' => 'Stalled cleared automatically: it was set before the training was reopened.',
        ],
    ];

    /**
     * The training's newest timeline entry id: the version a flag form carries,
     * so a change made from an older page can be recognised.
     */
    public function version(Training $training): int
    {
        return (int) TrainingActivity::where('training_id', $training->getKey())->max('id');
    }

    /**
     * Set or clear one flag by hand.
     *
     * @param  int|null  $seenVersion  version() when the form was rendered; null skips the check
     * @return string one of CHANGED, UNCHANGED (already in that state), CLOSED (training no
     *                longer open) or STALE (the timeline moved on since the form was rendered)
     */
    public function set(Training $training, string $flag, bool $state, ?int $userId, ?int $seenVersion = null): string
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

            if ($seenVersion !== null && $this->version($current) > $seenVersion) {
                return self::STALE;
            }

            $current->{$config['column']} = $state ? now() : null;
            $current->save();

            $this->record($current, $state ? $config['on'] : $config['off'], $userId);

            return self::CHANGED;
        });
    }

    /**
     * Clear flags made stale by a status change across open and closed.
     *
     * On close, every flag still set is cleared. On reopen, only flags set
     * before the reopen are cleared (a close whose own clearing failed leaves
     * them behind); a flag set after the reopen is kept. Reads the row under a
     * lock rather than trusting the model, which may have been loaded before a
     * flag was set, and does nothing if the status has moved on again since,
     * because that later change runs this itself.
     */
    public function clearOnStatusChange(Training $training, Carbon $changedAt): void
    {
        DB::transaction(function () use ($training, $changedAt) {
            $columns = array_merge(['status'], array_column(self::FLAGS, 'column'));
            $row = DB::table($training->getTable())->where('id', $training->getKey())->lockForUpdate()->first($columns);
            if ($row === null) {
                return;
            }

            $nowOpen = TrainingStatus::from((int) $row->status)->isOpen();
            if ($nowOpen !== $training->status->isOpen()) {
                return;
            }

            $stale = array_filter(self::FLAGS, function (array $config) use ($row, $nowOpen, $changedAt) {
                $value = $row->{$config['column']};

                return $value !== null && (! $nowOpen || Carbon::parse($value)->lt($changedAt));
            });
            if ($stale === []) {
                return;
            }

            $nulls = array_fill_keys(array_column($stale, 'column'), null);
            DB::table($training->getTable())->where('id', $training->getKey())->update($nulls);
            $training->forceFill($nulls)->syncOriginalAttributes(array_keys($nulls));

            foreach ($stale as $config) {
                $this->record($training, $nowOpen ? $config['reopened'] : $config['closed'], null);
            }
        });
    }

    private function record(Training $training, string $text, ?int $userId): void
    {
        ActivityLogService::warning(LogName::Training, rtrim($text, '.') . ' on training ' . $training->id);
        TrainingActivityController::create($training->id, 'COMMENT', null, null, $userId, $text);
    }
}
