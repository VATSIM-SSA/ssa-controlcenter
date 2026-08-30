<?php

namespace App\Livewire\Vatssa;

use App\Models\Vatssa\AvailabilityPoll;
use App\Models\Vatssa\AvailabilityResponse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
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
    public AvailabilityPoll $poll;

    /** This person's role in this poll, which decides the question being asked. */
    public string $role = AvailabilityPoll::ROLE_PARTICIPANT;

    /** @var array<int, string> ISO UTC slot start times. */
    public array $selected = [];

    /** Monday of the week on screen. */
    public string $weekStart;

    public bool $readOnly = false;

    public function mount(AvailabilityPoll $poll, ?string $role = null): void
    {
        $this->poll = $poll;
        $this->role = $role ?? $this->guessRole();

        $existing = $poll->responses()->where('user_id', Auth::id())->first();
        $this->selected = $existing?->slots ?? [];

        // Open on the first week that has any slots in it, not on today. A poll
        // for next month opening on an empty week looks broken.
        $this->weekStart = CarbonImmutable::parse($poll->starts_on)
            ->startOfWeek()->toDateString();

        $this->readOnly = ! $poll->isOpen();
    }

    /**
     * What this person is to this poll, when nobody said.
     *
     * The student on the training is the student. Everybody else is a
     * participant until a controller says otherwise -- guessing "examiner"
     * from an endorsement would let somebody answer a question they were not
     * asked.
     */
    private function guessRole(): string
    {
        if ($this->poll->training?->user_id === Auth::id()) {
            return AvailabilityPoll::ROLE_STUDENT;
        }

        return AvailabilityPoll::ROLE_PARTICIPANT;
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
            ['slots' => $this->selected, 'role' => $this->role],
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
            // Everybody else's answers, so the grid shows overlap while you
            // fill it in. Seeing that nobody else can do Tuesday is the whole
            // value of a shared grid over a chain of emails.
            'others' => $this->poll->heatmap(),
            'participants' => $this->poll->responses->count(),
        ]);
    }
}
