<?php

namespace App\Models\Vatssa;

use App\Http\Controllers\TrainingActivityController;
use App\Models\Training;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

/**
 * VATSSA: what the automation did, and what it noticed and could not fix.
 *
 * The thing that makes automation frightening to run is not that it acts. It is
 * that it acts SILENTLY, so the only way to notice a wrong decision is for
 * somebody to complain. Every automatic change now writes a row here, and every
 * training-scoped one is mirrored onto that training's own timeline.
 *
 * ## Record, never raise
 *
 * `record()` swallows its own failures. A log write that breaks the operation
 * it was describing turns an observability feature into an outage, and an
 * action log is precisely the code you least want in the critical path.
 */
class ActionLog extends Model
{
    protected $table = 'vatssa_action_log';

    protected $fillable = [
        'actor', 'action', 'summary', 'level', 'training_id', 'user_id', 'context',
    ];

    protected $casts = ['context' => 'array'];

    public const ACTOR_BOT = 'bot';

    public const ACTOR_SYSTEM = 'system';

    public const INFO = 'info';

    /** Noticed, deliberately not acted on. The rows worth reading. */
    public const WARNING = 'warning';

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Something the system DID.
     */
    public static function did(
        string $action,
        string $summary,
        ?int $trainingId = null,
        ?int $userId = null,
        array $context = [],
        string $actor = self::ACTOR_SYSTEM,
    ): void {
        self::record($action, $summary, self::INFO, $trainingId, $userId, $context, $actor);
    }

    /**
     * Something the system NOTICED and did not act on.
     *
     * An empty desk, an unconfigured Moodle course, a training mentorless for a
     * fortnight. Software that stays quiet about what it cannot handle is worse
     * than software that does nothing, because silence reads as "fine".
     */
    public static function noticed(
        string $action,
        string $summary,
        ?int $trainingId = null,
        ?int $userId = null,
        array $context = [],
        string $actor = self::ACTOR_SYSTEM,
    ): void {
        self::record($action, $summary, self::WARNING, $trainingId, $userId, $context, $actor);
    }

    private static function record(
        string $action,
        string $summary,
        string $level,
        ?int $trainingId,
        ?int $userId,
        array $context,
        string $actor,
    ): void {
        try {
            static::create([
                'actor' => $actor,
                'action' => $action,
                'summary' => mb_substr($summary, 0, 255),
                'level' => $level,
                'training_id' => $trainingId,
                'user_id' => $userId,
                'context' => $context ?: null,
            ]);

            // Mirror onto the training's own timeline, where the people working
            // it will actually see it. The division-wide view is this table;
            // the per-training view is upstream's activity log, and a change
            // that appears in neither may as well not have happened.
            if ($trainingId !== null) {
                TrainingActivityController::create(
                    $trainingId, 'COMMENT', null, null, null, $summary
                );
            }
        } catch (\Throwable $e) {
            // NEVER raise. A log write that breaks the operation it describes
            // turns an observability feature into an outage.
            Log::warning('VATSSA action log write failed', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function actorLabel(): string
    {
        return match ($this->actor) {
            self::ACTOR_BOT => 'Pipeline bot',
            self::ACTOR_SYSTEM => 'Control Center',
            default => 'CID ' . $this->actor,
        };
    }
}
