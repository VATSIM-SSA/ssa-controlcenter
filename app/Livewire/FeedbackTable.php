<?php

namespace App\Livewire;

use App\Helpers\FeedbackStatus;
use App\Models\Area;
use App\Models\Feedback;
use App\Models\ManagementReport;
use App\Models\Position;
use App\Models\User;
use App\Services\Sql\Sql;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class FeedbackTable extends Component
{
    use AuthorizesRequests, WithPagination;

    protected string $paginationTheme = 'bootstrap';

    #[Url]
    public int $perPage = 25;

    #[Url]
    public string $sortDirection = 'desc';

    #[Url]
    public string $search = '';

    #[Url]
    public string $controller = '';

    #[Url]
    public ?int $area = null;

    #[Url]
    public string $position = '';

    #[Url]
    public string $submitter = '';

    /**
     * Which statuses to show. DEFAULTS TO OPEN, and that default is the whole
     * point of #1467: a division that receives feedback steadily was scrolling
     * past everything it had already dealt with to find the one thing it had
     * not.
     *
     * Empty string means every status, so nothing is ever hidden for good --
     * closed feedback is one select away, and stays searchable.
     */
    #[Url]
    public string $status = FeedbackStatus::OPEN->value;

    /**
     * Toggled by the edit modal's open/close events so the controller and
     * position pick-lists (the full active-controller and position sets) are
     * only queried and rendered while the modal is actually open, never on a
     * plain table render.
     */
    public bool $showReferenceOptions = false;

    public function mount(): void
    {
        // The gate must re-run here: with `lazy`, hydration and pagination happen
        // in follow-up Livewire requests where the controller gate does not run.
        $this->authorize('viewFeedback', ManagementReport::class);
    }

    /**
     * Any live-bound filter or the per-page selector changing invalidates the
     * current page offset, so reset pagination whenever a property updates.
     * Page navigation goes through gotoPage()/setPage() rather than a property
     * write, so it does not trigger this hook.
     */
    public function updated(): void
    {
        $this->resetPage();
    }

    public function sortByReceived(): void
    {
        $this->sortDirection = $this->sortDirection === 'desc' ? 'asc' : 'desc';
        $this->resetPage();
    }

    /**
     * Reset every filter field (but not display preferences like per-page or
     * sort direction) back to its default.
     */
    public function clearFilters(): void
    {
        // `status` is reset with the rest, which returns it to OPEN rather than
        // to "all" -- clearing filters should give somebody their queue back,
        // not the entire archive.
        $this->reset(['search', 'controller', 'area', 'position', 'submitter', 'status']);
        $this->resetPage();
    }

    /**
     * Whether any filter field currently holds a value — drives the visibility
     * of the clear button.
     */
    public function hasActiveFilters(): bool
    {
        return $this->search !== ''
            || $this->controller !== ''
            || $this->position !== ''
            || $this->submitter !== ''
            || $this->area !== null
            // Not `!== ''`: OPEN is the default, so only a DEPARTURE from it
            // counts as a filter somebody has set and might want to clear.
            || $this->status !== FeedbackStatus::OPEN->value;
    }

    /**
     * Base query carrying the correlated/uncorrelated area scope, plus the
     * top-level user filters ANDed outside the scope closure so they can
     * only narrow, never widen, what the scope allows.
     */
    protected function baseQuery(): Builder
    {
        $direction = in_array($this->sortDirection, ['asc', 'desc'], true) ? $this->sortDirection : 'desc';

        return Feedback::visibleTo(auth()->user())
            ->with(['submitter', 'referenceUser', 'referencePosition.area'])
            ->when($this->search !== '', fn (Builder $q) => $q->where('feedback', 'like', '%' . $this->search . '%'))
            ->when($this->controller !== '', fn (Builder $q) => $q->whereHas('referenceUser', fn (Builder $q) => $q->whereRaw(Sql::concat('first_name', "' '", 'last_name') . ' like ?', ['%' . $this->controller . '%'])->orWhere('id', 'like', '%' . $this->controller . '%')))
            ->when($this->position !== '', fn (Builder $q) => $q->whereHas('referencePosition', fn (Builder $q) => $q->where('callsign', 'like', '%' . $this->position . '%')->orWhere('name', 'like', '%' . $this->position . '%')))
            ->when($this->area !== null, fn (Builder $q) => $q->whereHas('referencePosition', fn (Builder $q) => $q->where('area_id', $this->area)))
            ->when($this->submitter !== '', fn (Builder $q) => $q->whereHas('submitter', fn (Builder $q) => $q->where('first_name', 'like', '%' . $this->submitter . '%')->orWhere('last_name', 'like', '%' . $this->submitter . '%')->orWhere('id', 'like', '%' . $this->submitter . '%')))
            // Validated against the enum rather than passed through: a crafted
            // ?status= must narrow to a real status or do nothing at all.
            ->when(FeedbackStatus::tryFrom($this->status) !== null, fn (Builder $q) => $q->where('status', $this->status))
            ->orderBy('created_at', $direction);
    }

    public function render(): View
    {
        $perPage = in_array($this->perPage, [25, 50, 100], true) ? $this->perPage : 25;

        $feedbacks = $this->baseQuery()->paginate($perPage);

        return view('livewire.feedback-table', [
            'statuses' => FeedbackStatus::cases(),
            'feedbacks' => $feedbacks,
            'areas' => $this->filterAreas(),
            'editControllers' => $this->showReferenceOptions ? User::getActiveAtcMembers() : collect(),
            'editPositions' => $this->showReferenceOptions ? Position::all() : collect(),
        ]);
    }

    /**
     * Scoped area options for the filter select.
     *
     * @return Collection<int, Area>
     */
    protected function filterAreas(): Collection
    {
        $scope = auth()->user()->accessibleAreasForPermission('feedback.correlated.view');

        return $scope->isGlobal
            ? Area::orderBy('name')->get()
            : $scope->areas->sortBy('name')->values();
    }
}
