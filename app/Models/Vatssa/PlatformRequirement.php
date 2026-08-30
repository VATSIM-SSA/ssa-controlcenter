<?php

namespace App\Models\Vatssa;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * VATSSA: you must be reachable before you can be trained.
 *
 * ## The rule
 *
 * Discord and Moodle, both, before a training application is accepted.
 *
 * This inverts how the pipeline used to work. It USED TO check, after the
 * fact, whether a student had turned up on each platform, chase them when they
 * had not, and carry on regardless -- which meant the commonest stall in the
 * whole system was a student sitting in the queue who had never been reachable
 * at all. Nobody was doing anything wrong; the work simply could not start, and
 * the system had no way of saying so except a chaser email into silence.
 *
 * Requiring it at the door costs one extra step at the one moment somebody is
 * motivated to take it, and removes the stall entirely.
 *
 * ## The exemption
 *
 * A rule with no exit is a rule that gets disabled for everybody the first time
 * it is genuinely wrong. Someone whose country blocks Discord, a transferring
 * controller mid-move, an account stuck in support: an exemption row, with a
 * reason and a name against it, keeps the rule intact for everyone else.
 *
 * ATC training manager and admin only -- `training.platform-requirement.override`.
 *
 * ## What counts as "on Moodle"
 *
 * An ACCOUNT, not an enrolment. Enrolment is the division's job, not the
 * student's, and requiring something they cannot do themselves would make the
 * gate a trap rather than a door.
 */
class PlatformRequirement extends Model
{
    protected $table = 'vatssa_platform_exemptions';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = ['user_id', 'discord', 'moodle', 'reason', 'granted_by'];

    protected $casts = ['discord' => 'boolean', 'moodle' => 'boolean'];

    public const OVERRIDE = 'training.platform-requirement.override';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /**
     * What is missing before this person can apply for training.
     *
     * Empty means they are good to go. The strings are shown to the student, so
     * each one says what to do rather than what is wrong: "you are not on
     * Discord" is a verdict, "join the Discord server" is an instruction.
     *
     * @return array<int, string>
     */
    public static function missingFor(User $user): array
    {
        $platform = UserPlatform::find($user->id);
        $exempt = static::find($user->id);
        $missing = [];

        // No platform row at all means the bot has never looked at this person,
        // which is NOT the same as "they are not there". Treated as missing on
        // purpose: the honest answer is "we cannot see you yet", and the fix --
        // linking the accounts -- is the same either way.
        if (! ($platform?->on_discord) && ! ($exempt?->discord)) {
            $missing[] = 'join the VATSSA Discord server and link your VATSIM CID';
        }

        if (! ($platform?->on_moodle) && ! ($exempt?->moodle)) {
            $missing[] = 'create an account on the VATSSA training site (Moodle)';
        }

        return $missing;
    }

    public static function isSatisfiedBy(User $user): bool
    {
        return static::missingFor($user) === [];
    }

    /**
     * Has the bot ever looked at this person?
     *
     * Separates "we checked and you are not there" from "we have not checked".
     * The gate treats both as missing, but the MESSAGE should not: telling
     * somebody who joined an hour ago that they are not on Discord is how a
     * correct rule gets a reputation for being broken.
     */
    public static function hasBeenChecked(User $user): bool
    {
        return UserPlatform::where('user_id', $user->id)
            ->whereNotNull('checked_at')
            ->exists();
    }
}
