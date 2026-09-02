<?php

namespace App\Models\Vatssa;

use App\Models\Training;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * VATSSA: "when can you do this?", asked once and read by everything.
 *
 * @see database/migrations-vatssa/2026_08_31_120000_vatssa_availability.php
 */
class AvailabilityPoll extends Model
{
    protected $table = 'vatssa_availability_polls';

    protected $fillable = [
        'purpose', 'title', 'description', 'starts_on', 'ends_on', 'slot_minutes',
        'training_id', 'created_by', 'submitted_at', 'confirmed_at', 'confirmed_slot',
        'visibility',
        'confirmed_by',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'submitted_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'confirmed_slot' => 'datetime',
    ];

    /*
    | What the poll is FOR.
    |
    | These are labels, not workflow. There used to be a `cpt` purpose that the
    | nine-stage exam workflow keyed off, and the tool inherited a lot of
    | machinery from it -- a notice rule, examiner visibility, a role that meant
    | "the events team has cleared this". None of that is a property of asking
    | a group when they are free, and all of it made the tool feel like a
    | workflow you were halfway through rather than something you could just
    | use.
    |
    | Adding a purpose is one line here and one option in the form. It changes
    | the label and nothing else, which is the point.
    */
    public const MENTORING = 'mentoring';

    public const MEETING = 'meeting';

    public const SESSION = 'session';

    public const OTHER = 'other';

    /*
    | WHO may open it.
    |
    | `INVITED` is the default and the safe one: a response row is the
    | invitation, so "only this person" and "only these few people" are the same
    | setting with a different number of names.
    |
    | `LINK` is anybody signed in who has the URL. Still behind authentication
    | on purpose -- a poll is a list of when named members are at home, and
    | publishing that to the open internet is not a convenience setting.
    */
    public const VISIBILITY_INVITED = 'invited';

    public const VISIBILITY_LINK = 'link';

    public const VISIBILITIES = [
        self::VISIBILITY_INVITED => 'Only the people I invite',
        self::VISIBILITY_LINK => 'Anybody with the link',
    ];

    /** Purpose => label, in the order the form offers them. */
    public const PURPOSES = [
        self::MENTORING => 'Mentoring session',
        self::SESSION => 'Training or group session',
        self::MEETING => 'Meeting',
        self::OTHER => 'Something else',
    ];

    /** The student fills this in; the mentor is only ever kept in the loop. */
    public const ROLE_STUDENT = 'student';

    public const ROLE_EXAMINER = 'examiner';

    public const ROLE_MENTOR = 'mentor';

    /** The events team marks what is CLEAR, not when they are free. */
    public const ROLE_EVENTS = 'events';

    public const ROLE_PARTICIPANT = 'participant';

    public function responses(): HasMany
    {
        return $this->hasMany(AvailabilityResponse::class, 'poll_id');
    }

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function purposeLabel(): string
    {
        return self::PURPOSES[$this->purpose] ?? self::PURPOSES[self::OTHER];
    }

    /**
     * Every slot in the window, as UTC start times.
     *
     * ## Why the day is bounded
     *
     * A month of 24-hour days at half-hour resolution is 1,488 checkboxes, and
     * a grid nobody can read is a grid nobody fills in. VATSSA controls
     * evenings and weekends, so the window is 06:00-23:00 Zulu -- wide enough
     * for every real session and narrow enough to render.
     *
     * @return Collection<int, CarbonImmutable>
     */
    public function slots(): Collection
    {
        $slots = collect();
        $day = CarbonImmutable::parse($this->starts_on)->startOfDay();
        $last = CarbonImmutable::parse($this->ends_on)->startOfDay();

        while ($day <= $last) {
            $cursor = $day->setTime(self::dayStarts(), 0);
            $end = $day->setTime(self::dayEnds(), 0);

            while ($cursor < $end) {
                $slots->push($cursor);
                $cursor = $cursor->addMinutes($this->slot_minutes);
            }

            $day = $day->addDay();
        }

        return $slots;
    }

    /**
     * The window of the day the grid draws.
     *
     * Were constants. Now config, because 06:00-23:00 Zulu is right for a
     * division sitting between UTC+0 and UTC+4 and wrong for one that is not,
     * and a constant makes that look like a decision nobody is allowed to
     * revisit.
     *
     * Clamped rather than trusted: a start after the end produces a poll with
     * no slots at all, which renders as an empty grid with no error and looks
     * like the feature is broken.
     */
    public static function dayStarts(): int
    {
        return max(0, min(23, (int) config('vatssa.availability.day_starts', 6)));
    }

    public static function dayEnds(): int
    {
        return max(self::dayStarts() + 1, min(24, (int) config('vatssa.availability.day_ends', 23)));
    }

    /** What the grid calls the times. A label, never a conversion -- see config. */
    /**
     * How many weeks this poll covers.
     *
     * Asked for because the grid shows one week at a time, so a poll can look
     * like a question about next week when it is a question about the next
     * five. Somebody who marks one week and stops has answered a fifth of it,
     * and nothing on the page told them so.
     *
     * Counted in calendar weeks from Monday, the same boundary the grid pages
     * on, so "week 2 of 5" always agrees with what the arrows do.
     */
    public function weekCount(): int
    {
        $first = CarbonImmutable::parse($this->starts_on)->startOfWeek();
        $last = CarbonImmutable::parse($this->ends_on)->startOfWeek();

        return (int) $first->diffInWeeks($last) + 1;
    }

    /**
     * Which week of the poll a given Monday is, 1-based.
     */
    public function weekIndex(CarbonImmutable|string $weekStart): int
    {
        $first = CarbonImmutable::parse($this->starts_on)->startOfWeek();
        $week = CarbonImmutable::parse($weekStart)->startOfWeek();

        return (int) $first->diffInWeeks($week) + 1;
    }

    public static function timezoneLabel(): string
    {
        return (string) config('vatssa.availability.timezone_label', 'Zulu (UTC+0)');
    }

    /**
     * Slot start time => the users who can make it.
     *
     * The heat map. Everything the workflow needs to answer -- who is free
     * when, which slots the events team has cleared, whether an examiner and a
     * student overlap at all -- is a read of this one structure.
     *
     * @return array<string, array<int, int>>
     */
    public function heatmap(?string $role = null): array
    {
        $map = [];

        foreach ($this->responses as $response) {
            if ($role !== null && $response->role !== $role) {
                continue;
            }

            foreach ($response->slots ?? [] as $slot) {
                $map[$slot][] = $response->user_id;
            }
        }

        return $map;
    }

    /**
     * Slots that work for everybody who matters.
     *
     * ## Why this is an intersection and not a vote
     *
     * A meeting can go ahead with most people. Some things cannot: the person the
     * examiner and a clear calendar are all required, and "three out of four"
     * is not a time anybody can sit an exam.
     *
     * Roles nobody has answered for are IGNORED rather than treated as
     * blocking. An examiner who has not replied yet must not make every slot
     * look impossible -- that reads as "no times work" when the truth is
     * "we are still waiting".
     *
     * @param  array<int, string>  $roles
     * @return array<int, string>
     */
    public function agreedSlots(array $roles): array
    {
        $answered = $this->responses->groupBy('role');
        $sets = [];

        foreach ($roles as $role) {
            if (! $answered->has($role)) {
                continue;
            }

            $sets[] = $answered[$role]
                ->flatMap(fn (AvailabilityResponse $r) => $r->slots ?? [])
                ->unique()
                ->all();
        }

        if ($sets === []) {
            return [];
        }

        return array_values(array_intersect(...$sets));
    }

    /**
     * May this person see, and answer, this poll?
     *
     * ## Why this is not "anyone signed in"
     *
     * A poll carries names and the hours those people are free. That is not
     * secret, and it is not nothing either: it is a list of when named members
     * are at home, and a scheduling tool that publishes it to the whole
     * division by default is one nobody should have to think twice about.
     *
     * ## Who qualifies
     *
     * The person who asked, the student it is about (when it is attached to a
     * training), anybody already invited -- they have a response row, even an
     * empty one -- and staff who work the queue. Plus, if the poll was created
     * as "anybody with the link", anybody signed in who has it.
     */
    public function isVisibleTo(User $user): bool
    {
        if ($this->created_by === $user->id) {
            return true;
        }

        if ($this->training?->user_id === $user->id) {
            return true;
        }

        // A response row is the invitation. Created when somebody is added to
        // a poll, so it exists before they have marked anything.
        if ($this->responses->contains('user_id', $user->id)) {
            return true;
        }

        if ($user->hasPermission('fir.management.reports.view')) {
            return true;
        }

        // "Anybody with the link" means exactly that, for somebody signed in.
        // The URL carries a sequential id, so this is not a secret -- it is a
        // deliberate choice by whoever asked the question, and the default is
        // the other one.
        return $this->visibility === self::VISIBILITY_LINK;
    }

    /**
     * May this person invite others, close the poll, or change it?
     *
     * The person who asked owns it. Staff who work the queue can act on one
     * that has been abandoned, which is the case that otherwise needs a
     * developer and a database console.
     */
    public function isManageableBy(User $user): bool
    {
        return $this->created_by === $user->id
            || $user->hasPermission('fir.management.reports.view');
    }

    public function visibilityLabel(): string
    {
        return self::VISIBILITIES[$this->visibility] ?? self::VISIBILITIES[self::VISIBILITY_INVITED];
    }

    public function isOpen(): bool
    {
        return $this->confirmed_at === null;
    }
}
