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
        'confirmed_by',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'submitted_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'confirmed_slot' => 'datetime',
    ];

    public const CPT = 'cpt';

    public const MENTORING = 'mentoring';

    public const MEETING = 'meeting';

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
        return match ($this->purpose) {
            self::CPT => 'Practical exam',
            self::MEETING => 'Meeting',
            default => 'Mentoring session',
        };
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
            $cursor = $day->setTime(self::DAY_STARTS, 0);
            $end = $day->setTime(self::DAY_ENDS, 0);

            while ($cursor < $end) {
                $slots->push($cursor);
                $cursor = $cursor->addMinutes($this->slot_minutes);
            }

            $day = $day->addDay();
        }

        return $slots;
    }

    public const DAY_STARTS = 6;

    public const DAY_ENDS = 23;

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
     * A meeting can go ahead with most people. A CPT cannot: the student, the
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
     * The person who asked, the student it is about, anybody already invited
     * (they have a response row, even an empty one), and staff who work the
     * queue. A CPT poll is also visible to examiners, because being offered
     * the times is the entire point of the step.
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

        return $this->purpose === self::CPT
            && $user->hasPermission('examinations.manage');
    }

    public function isOpen(): bool
    {
        return $this->confirmed_at === null;
    }

    /**
     * Is the confirmation late enough to be legal?
     *
     * VATSSA's rule: everything settled at least seven days before the exam --
     * the examiner confirmed, the events team told, and myVATSIM uploaded.
     * Miss it and the CPT postpones. A single deadline, so there is one date to
     * argue about rather than three.
     */
    public function meetsNotice(CarbonImmutable|string $slot): bool
    {
        if ($this->purpose !== self::CPT) {
            return true;
        }

        return CarbonImmutable::parse($slot)->greaterThanOrEqualTo(
            CarbonImmutable::now()->addDays(self::CPT_NOTICE_DAYS)
        );
    }

    public const CPT_NOTICE_DAYS = 7;
}
