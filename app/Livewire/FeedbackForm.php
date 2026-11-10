<?php

namespace App\Livewire;

use App\Models\Position;
use App\Models\User;
use App\Services\Sql\Sql;
use App\Services\StatisticsService;
use App\Services\VatsimOnlineControllers;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The feedback submission form. Livewire drives the controller search and
 * the session picker; the form itself posts to FeedbackController@store, so
 * StoreFeedbackRequest validates everything that is saved.
 *
 * @property-read EloquentCollection<int, Position> $positions
 */
class FeedbackForm extends Component
{
    /** How far back the session picker looks. */
    public const SESSION_DAYS = 14;

    /** How many sessions the picker offers. */
    public const SESSION_LIMIT = 10;

    /** How many controllers the search lists. */
    public const SEARCH_LIMIT = 10;

    #[Locked]
    public ?int $areaId = null;

    /**
     * The submitter's recent flights, for the flight picker (see PilotFlightHistoryService).
     *
     * @var array<int, array{callsign: string, date: string, departure: ?string, arrival: ?string}>
     */
    #[Locked]
    public array $flights = [];

    public string $search = '';

    #[Locked]
    public ?int $controller = null;

    #[Locked]
    public string $controllerLabel = '';

    /**
     * The controller's recent sessions, newest first.
     *
     * @var array<int, array{callsign: string, start: string, end: string, label: string}>
     */
    #[Locked]
    public array $sessions = [];

    /** Index into $sessions, or '' for none. */
    public string $session = '';

    public string $position = '';

    /**
     * @param  array<int, array{callsign: string, date: string, departure: ?string, arrival: ?string}>  $flights
     */
    public function mount(?int $areaId = null, array $flights = []): void
    {
        $this->areaId = $areaId;
        $this->flights = $flights;
        $this->position = (string) old('position', '');

        if ($oldController = old('controller')) {
            $this->selectController((int) $oldController);
        }
    }

    /**
     * Pick a controller from the search results. Only someone else on a roster
     * can be picked, so a crafted request cannot name anyone outside it.
     */
    public function selectController(int $cid): void
    {
        $user = $cid !== auth()->id() && User::getFeedbackControllerIds()->contains($cid) ? User::find($cid) : null;

        if ($user === null) {
            return;
        }

        $this->controller = $user->id;
        $this->controllerLabel = "{$user->name} ({$user->id})";
        $this->search = '';
        $this->session = '';
        $this->sessions = $this->recentSessions($user);
    }

    public function clearController(): void
    {
        $this->reset(['controller', 'controllerLabel', 'sessions', 'session', 'search']);
    }

    public function updatedSession(): void
    {
        if ($this->session !== '' && ! isset($this->sessions[(int) $this->session])) {
            $this->session = '';
        }

        // A picked session names the position, when it is one CC knows.
        if ($this->session !== '') {
            $callsign = $this->sessions[(int) $this->session]['callsign'];
            $this->position = Position::where('callsign', $callsign)->exists() ? $callsign : '';
        }
    }

    /**
     * Search results: controllers online now in this area first, then the
     * rest matching the name or CID typed.
     *
     * @return array{online: Collection<int, array{id: int, name: string, detail: string}>, others: Collection<int, array{id: int, name: string, detail: string}>}
     */
    #[Computed]
    public function results(): array
    {
        $term = trim($this->search);
        $rosterIds = User::getFeedbackControllerIds();
        $online = $this->onlineInArea()->only($rosterIds->all());

        // Roster members among $ids whose name or CID matches what was typed.
        $search = fn (array $ids, int $limit) => User::query()
            ->whereIn('id', $ids)
            ->whereKeyNot(auth()->id())
            ->when($term !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereRaw(Sql::concat('first_name', "' '", 'last_name') . ' like ?', ['%' . $term . '%'])
                ->orWhere('id', 'like', '%' . $term . '%')))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit($limit)
            ->get();

        $onlineRows = $online->isEmpty() ? collect() : $search($online->keys()->all(), self::SEARCH_LIMIT)
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'detail' => $online->get($user->id)]);

        $otherRows = $term === '' ? collect() : $search($rosterIds->diff($online->keys())->values()->all(), self::SEARCH_LIMIT)
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'detail' => (string) $user->id]);

        return ['online' => $onlineRows->values(), 'others' => $otherRows->values()];
    }

    /**
     * @return EloquentCollection<int, Position>
     */
    #[Computed]
    public function positions(): EloquentCollection
    {
        return Position::query()
            ->when($this->areaId, fn ($query) => $query->where('area_id', $this->areaId))
            ->orderBy('callsign')
            ->get();
    }

    public function render(): View
    {
        return view('livewire.feedback-form', [
            'selectedSession' => $this->session !== '' ? ($this->sessions[(int) $this->session] ?? null) : null,
        ]);
    }

    /**
     * Online controllers whose callsign belongs to this area, keyed by CID.
     * A callsign belongs when it starts with the prefix (e.g. "EKCH") of one
     * of the area's positions; without an area, everyone online counts.
     *
     * @return Collection<int, string>
     */
    protected function onlineInArea(): Collection
    {
        $online = app(VatsimOnlineControllers::class)->callsignsByCid();

        if ($this->areaId === null) {
            return $online;
        }

        $prefixes = $this->positions
            ->map(fn (Position $position): string => (string) strtok($position->callsign, '_'))
            ->filter()
            ->unique();

        return $online->filter(fn (string $callsign): bool => $prefixes->contains(strtok($callsign, '_')));
    }

    /**
     * @return array<int, array{callsign: string, start: string, end: string, label: string}>
     */
    protected function recentSessions(User $user): array
    {
        $now = Carbon::now('UTC');

        $sessions = app(StatisticsService::class)->getRecentSessionsSummary(
            (string) $user->id,
            $now->copy()->subDays(self::SESSION_DAYS),
            $now,
            self::SESSION_LIMIT,
            self::SESSION_DAYS,
        );

        return collect($sessions)
            ->filter(fn (array $session): bool => isset($session['start'], $session['end']) && $session['callsign'] !== '—')
            ->map(function (array $session): array {
                $start = Carbon::parse($session['start'])->utc();
                $end = Carbon::parse($session['end'])->utc();

                return [
                    'callsign' => $session['callsign'],
                    'start' => $start->toIso8601String(),
                    'end' => $end->toIso8601String(),
                    'label' => $session['callsign'] . ' · ' . $start->format('j M, Hi') . '–' . $end->format('Hi') . 'z',
                ];
            })
            ->values()
            ->all();
    }
}
