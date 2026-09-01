<?php

namespace App\Models\Vatssa;

use App\Helpers\ExamStage;
use App\Models\Position;
use App\Models\Training;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * VATSSA: one practical exam, from "the mentor thinks they are ready" to
 * "somebody is sitting it on Tuesday".
 *
 * @see database/migrations-vatssa/2026_08_31_130000_vatssa_exams.php
 */
class Exam extends Model
{
    protected $table = 'vatssa_exams';

    protected $guarded = [];

    protected $casts = [
        'stage' => ExamStage::class,
        'authorised_at' => 'datetime',
        'availability_submitted_at' => 'datetime',
        'events_cleared_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'scheduled_for' => 'datetime',
        'published_at' => 'datetime',
        'banner_made' => 'boolean',
        'on_discord' => 'boolean',
        'on_myvatsim' => 'boolean',
        'on_social' => 'boolean',
        'vatsim_approved' => 'boolean',
    ];

    /**
     * Everything must be settled this long before the exam.
     *
     * VATSSA's rule, and it is ONE deadline rather than three: the examiner
     * confirmed, the events team told, and myVATSIM uploaded. A single date to
     * argue about instead of a chain of them.
     */
    public const NOTICE_DAYS = 7;

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }

    public function examiner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'examiner_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function authoriser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorised_by');
    }

    public function poll(): BelongsTo
    {
        return $this->belongsTo(AvailabilityPoll::class, 'poll_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function student(): ?User
    {
        return $this->training?->user;
    }

    /**
     * The events team's checklist, as label => done.
     *
     * Ordered as they actually happen. `vatsim_approved` last because it is the
     * only one that waits on somebody outside the division, and it is therefore
     * the one that is still outstanding at 6pm the night before.
     *
     * @return array<string, bool>
     */
    public function checklist(): array
    {
        return [
            'Banner made' => $this->banner_made,
            'On the Discord calendar' => $this->on_discord,
            'Uploaded to myVATSIM' => $this->on_myvatsim,
            'Posted to social media' => $this->on_social,
            'Approved by VATSIM' => $this->vatsim_approved,
        ];
    }

    public function checklistDone(): bool
    {
        return ! in_array(false, $this->checklist(), true);
    }

    public function checklistOutstanding(): array
    {
        return array_keys(array_filter($this->checklist(), fn ($done) => ! $done));
    }

    /**
     * Slots that work for the student AND are clear of division plans.
     *
     * The intersection, not a vote. A meeting can go ahead with most people; an
     * exam cannot happen at a time the student is busy or the calendar is
     * blocked, and "three out of four" is not a time anybody can sit a CPT.
     *
     * @return array<int, string>
     */
    public function offerableSlots(): array
    {
        if ($this->poll === null) {
            return [];
        }

        return array_values(array_filter(
            $this->poll->agreedSlots([
                AvailabilityPoll::ROLE_STUDENT,
                AvailabilityPoll::ROLE_EVENTS,
            ]),
            // Anything inside the notice period is not on the table, so it is
            // never shown to an examiner. Offering a slot that cannot legally
            // be taken is how a rule gets broken by somebody being helpful.
            fn (string $slot) => $this->meetsNotice($slot),
        ));
    }

    public function meetsNotice(CarbonImmutable|string $slot): bool
    {
        return CarbonImmutable::parse($slot)
            ->greaterThanOrEqualTo(CarbonImmutable::now()->addDays(self::NOTICE_DAYS));
    }

    /**
     * Has a confirmed exam fallen inside the notice period?
     *
     * Not the same question as `meetsNotice`. This one is about an exam that
     * WAS legal when it was confirmed and is now too close because the events
     * team has not finished -- the case the rule exists for, and the one the
     * daily sweep has to catch.
     */
    public function noticeBreached(): bool
    {
        return $this->scheduled_for !== null
            && ! $this->checklistDone()
            && $this->scheduled_for->lessThan(now()->addDays(self::NOTICE_DAYS));
    }

    /**
     * Move to the next stage, refusing anything the workflow does not allow.
     *
     * Returns false rather than throwing: every caller is a controller action
     * responding to a click, and a 500 is a worse answer to "somebody pressed
     * the button twice" than a redirect saying so.
     */
    public function moveTo(ExamStage $stage): bool
    {
        if (! $stage->canFollow($this->stage)) {
            return false;
        }

        $this->stage = $stage;

        return $this->save();
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('stage', array_map(
            fn (ExamStage $s) => $s->value,
            ExamStage::open()
        ));
    }
}
