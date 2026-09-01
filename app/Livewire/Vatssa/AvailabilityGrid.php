<?php

namespace App\Livewire\Vatssa;

use App\Models\Vatssa\AvailabilityPoll;
use App\Models\Vatssa\AvailabilityResponse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * VATSSA: the availability grid.
 *
 * ## Why drag-to-paint rather than checkboxes
 *
 * A month of half-hour slots is hundreds of cells. Clicking each one is not a
 * form, it is a chore, and a chore is a form nobody finishes -- which in this
 * workflow means a CPT that never gets scheduled because the student gave up
 * on step three.
 *
 * So the grid paints: press, drag, release. The client does the painting and
 * only the result is sent, because a Livewire round trip per cell would make
 * dragging feel broken on any connection worse than a good one, and half this
 * division is not on a good one.
 *
 * ## One week at a time
 *
 * The window is a month; the grid shows a week. Rendering 31 columns produces
 * something that is technically all there and practically unreadable on a
 * laptop, and completely unusable on a phone.
 *
 * ## Roles change what the grid MEANS
 *
 * A student marks when they are free. The events team marks which of those
 * times are clear of division plans -- including the ones not published yet,
 * which is exactly why that step cannot be a calendar query. An examiner marks
 * which of the cleared times they can take. Same grid, three questions.
 */
class AvailabilityGrid extends Component
{
    /*
    |--------------------------------------------------------------------------
    | Everything that decides WHAT MAY BE WRITTEN is #[Locked].
    |--------------------------------------------------------------------------
    |
    | Livewire rehydrates public properties from the request payload on every
    | call. Without #[Locked] the browser owns them, and three of these decide
    | authorisation rather than presentation:
    |
    | `role` was the bad one. A student could paint their own availability,
    | change `role` to 'events' in the payload, and paint again -- writing a
    | response row claiming the events team had cleared those times. Exam
    | ::offerableSlots() intersects the student role with the events role, so
    | the exam would then go out to examiners offering times the events team
    | never saw. A student could book their own CPT around the calendar.
    |
    | `readOnly` guards a settled poll; the client could set it false and edit
    | availability for an exam already confirmed.
    |
    | `poll` would let one component instance be pointed at another poll --
    | paint() re-checks visibility, so that one was covered, but it should not
    | have depended on remembering to.
    |
    | `selected` and `weekStart` stay open: they are what the person is
    | choosing, and paint() validates both against the poll's own slot list.
    */
    #[Locked]
    public AvailabilityPoll $poll;

    /** This person's role in this poll, which decides the question being asked. */
    #[Locked]
    public string $role = AvailabilityPoll::ROLE_PARTICIPANT;

    /** @var array<int, string> ISO UTC slot start times. */
    public array $selected = [];

    /** Monday of the week on screen. */
    public string $weekStart;

    #[Locked]
    public bool $readOnly = false;

    public function mount(AvailabilityPoll $poll, ?string $role = null): void
    {
        // Checked here as well as in the controller. A Livewire component is a
        // separate HTTP endpoint: it is mounted from a request the browser
        // makes on its own, so a check on the page that renders it is not a
        // check on the component.
        abort_unless($poll->isVisibleTo(Auth::user()), 403);

        $this->poll = $poll;
        $this->role = $this->resolveRole($role);

        $existing = $poll->responses()->where('user_id', Auth::id())->first();
        $this->selected = $existing?->slots ?? [];

        // Open on the first week that has any slots in it, not on today. A poll
        // for next month opening on an empty week looks broken.
        $this->weekStart = CarbonImmutable::parse($poll->starts_on)
            ->startOfWeek()->toDateString();

        $this->readOnly = ! $poll->isOpen();
    }

    /**
     * What this person is to this poll, verified rather than taken.
     *
     * The view asks for a role -- the exam page mounts the same grid as
     * 'student' on one screen and 'events' on another -- and this is where that
     * request is CHECKED. A caller asking for a role the person does not hold
     * gets 'participant', not an error: the grid still renders and still
     * records their times, it simply does not record them as somebody else's.
     *
     * #[Locked] stops the browser changing this after mount. This stops a view
     * being wrong in the first place, which is the half a locked property
     * cannot cover.
     */
    private function resolveRole(?string $requested): string
    {
        $user = Auth::user();
        $isStudent = $this->poll->training?->user_id === $user->id;

        $allowed = [AvailabilityPoll::ROLE_PARTICIPANT];

        if ($isStudent) {
            $allowed[] = AvailabilityPoll::ROLE_STUDENT;
        }

        // Clearing the calendar is the events team's job and nobody else's --
        // the same separation ExamPolicy::clear() enforces. See the note there
        // on why this cannot be a bookings query.
        if ($user->hasPermission('events.exams.manage')) {
            $allowed[] = AvailabilityPoll::ROLE_EVENTS;
        }

        if ($user->hasPermission('examinations.manage')) {
            $allowed[] = AvailabilityPoll::ROLE_EXAMINER;
        }

        if ($this->poll->training?->mentors->contains($user->id)) {
            $allowed[] = AvailabilityPoll::ROLE_MENTOR;
        }

        if ($requested !== null && in_array($requested, $allowed, true)) {
            return $requested;
        }

        return $isStudent
            ? AvailabilityPoll::ROLE_STUDENT
            : AvailabilityPoll::ROLE_PARTICIPANT;
    }

    public function previousWeek(): void
    {
        $this->shiftWeek(-1);
    }

    public function nextWeek(): void
    {
        $this->shiftWeek(1);
    }

    private function shiftWeek(int $by): void
    {
        $moved = CarbonImmutable::parse($this->weekStart)->addWeeks($by);

        // Clamped to the poll window. Letting somebody page into empty weeks
        // makes a bounded question feel unbounded.
        $first = CarbonImmutable::parse($this->poll->starts_on)->startOfWeek();
        $last = CarbonImmutable::parse($this->poll->ends_on)->startOfWeek();

        if ($moved->between($first, $last)) {
            $this->weekStart = $moved->toDateString();
        }
    }

    /**
     * Save what the client painted.
     *
     * @param  array<int, string>  $slots
     */
    public function paint(array $slots): void
    {
        if ($this->readOnly) {
            return;
        }

        // And again on write. `$poll` is rehydrated from the request on every
        // Livewire call, so mount() ran once, in the past, on a different
        // request -- it does not stand as a guard for this one.
        abort_unless($this->poll->isVisibleTo(Auth::user()), 403);

        // Only slots that are genuinely in this poll. The list comes from the
        // browser, so it is an assertion rather than a fact: without this, a
        // crafted payload could store arbitrary strings that every reader
        // downstream then has to defend against.
        $legal = $this->poll->slots()->map(fn ($s) => $s->toIso8601String())->flip();

        $this->selected = collect($slots)
            ->filter(fn ($slot) => $legal->has($slot))
            ->unique()
            ->values()
            ->all();

        AvailabilityResponse::updateOrCreate(
            ['poll_id' => $this->poll->id, 'user_id' => Auth::id()],
            // Re-derived on every write rather than trusting the property, even
            // though it is #[Locked]. Locked is a Livewire guarantee; this is
            // the application's own, and the cost of being wrong here is a
            // student clearing their own calendar.
            ['slots' => $this->selected, 'role' => $this->resolveRole($this->role)],
        );
    }

    public function clearWeek(): void
    {
        if ($this->readOnly) {
            return;
        }

        $days = $this->days()->map(fn ($d) => $d->toDateString())->all();

        $this->paint(collect($this->selected)
            ->reject(fn ($slot) => in_array(substr($slot, 0, 10), $days, true))
            ->values()
            ->all());
    }

    /**
     * The seven days on screen, clipped to the poll window.
     */
    public function days()
    {
        $start = CarbonImmutable::parse($this->weekStart);
        $from = CarbonImmutable::parse($this->poll->starts_on)->startOfDay();
        $to = CarbonImmutable::parse($this->poll->ends_on)->startOfDay();

        return collect(range(0, 6))
            ->map(fn ($i) => $start->addDays($i))
            ->filter(fn ($day) => $day->betweenIncluded($from, $to))
            ->values();
    }

    /**
     * The rows: one per slot time, shared across every day on screen.
     */
    public function times()
    {
        $times = collect();
        $cursor = CarbonImmutable::parse($this->weekStart)
            ->setTime(AvailabilityPoll::DAY_STARTS, 0);
        $end = CarbonImmutable::parse($this->weekStart)
            ->setTime(AvailabilityPoll::DAY_ENDS, 0);

        while ($cursor < $end) {
            $times->push($cursor->format('H:i'));
            $cursor = $cursor->addMinutes($this->poll->slot_minutes);
        }

        return $times;
    }

    public function render()
    {
        return view('livewire.vatssa.availability-grid', [
            'days' => $this->days(),
            'times' => $this->times(),
            // Everybody ELSE's answers, so the grid shows overlap while you
            // fill it in. Seeing that nobody else can do Tuesday is the whole
            // value of a shared grid over a chain of emails.
            //
            // Your own response is stripped out. Leaving it in made the tooltip
            // say "1 other person free" about a slot only you had marked, which
            // is the one number on this page nobody can afford to distrust.
            'others' => collect($this->poll->heatmap())
                ->map(fn (array $ids) => array_values(array_diff($ids, [Auth::id()])))
                ->filter()
                ->all(),
            'participants' => $this->poll->responses->count(),
        ]);
    }
}
