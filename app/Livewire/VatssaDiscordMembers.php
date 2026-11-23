<?php

namespace App\Livewire;

use App\Http\Controllers\VatssaDiscordSyncController as Sync;
use App\Services\VatssaDiscordRules;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

// VATSSA: every Discord account the bot has seen.
class VatssaDiscordMembers extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    private const TABS = ['server' => 'In the server', 'not_checked' => 'Not checked', 'not_vatsim' => 'Not VATSIM', 'bots' => 'Bots', 'left' => 'Left'];

    #[Url(except: 'server')]
    public string $show = 'server';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'name', except: '')]
    public string $filterName = '';

    #[Url(as: 'cid', except: '')]
    public string $filterCid = '';

    #[Url(as: 'status', except: '')]
    public string $filterStatus = '';

    #[Url(as: 'role', except: '')]
    public string $filterRole = '';

    #[Url(as: 'checked', except: '')]
    public string $filterChecked = '';

    public ?string $historyFor = null;

    // Runs on every Livewire request, where the page controller's check does not.
    public function boot(): void
    {
        abort_unless(Auth::user()?->hasPermission('vatssa.discord.members.view'), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'filter')) {
            $this->resetPage();
        }
    }

    public function setTab(string $tab): void
    {
        abort_unless(array_key_exists($tab, self::TABS), 404);
        $this->show = $tab;
        $this->resetPage();
    }

    public function openHistory(string $discordId): void
    {
        abort_unless(preg_match('/^\d{15,21}$/', $discordId) === 1, 404);
        $this->historyFor = $discordId;
    }

    public function closeHistory(): void
    {
        $this->historyFor = null;
    }

    private function scope(string $tab): Builder
    {
        return DB::table('vatssa_discord_members')
            ->when($tab === 'server', fn ($q) => $q->where('in_server', true))
            ->when($tab === 'not_checked', fn ($q) => $q->where('in_server', true)->where('is_bot', false)->whereNull('vatsim_linked'))
            ->when($tab === 'not_vatsim', fn ($q) => $q->where('in_server', true)->where('is_bot', false)->where('vatsim_linked', false))
            ->when($tab === 'bots', fn ($q) => $q->where('in_server', true)->where('is_bot', true))
            ->when($tab === 'left', fn ($q) => $q->where('in_server', false));
    }

    /**
     * The column filters. Every value comes from the browser, so each is bound or matched against a fixed list.
     */
    private function applyFilters(Builder $query): Builder
    {
        $name = trim($this->filterName);
        $cid = trim($this->filterCid);

        return $query
            ->when($name !== '', fn ($q) => $q->where('name', 'like', '%' . addcslashes($name, '%_\\') . '%'))
            ->when($cid !== '', fn ($q) => ctype_digit($cid)
                ? $q->where(fn ($w) => $w->where('user_id', 'like', $cid . '%')->orWhere('vatsim_cid', 'like', $cid . '%'))
                : $q->whereRaw('1 = 0'))
            ->when($this->filterStatus === 'bot', fn ($q) => $q->where('is_bot', true))
            ->when($this->filterStatus === 'vatsim', fn ($q) => $q->where('is_bot', false)->whereNotNull('user_id'))
            ->when($this->filterStatus === 'no_cc', fn ($q) => $q->where('is_bot', false)->whereNull('user_id')->where('vatsim_linked', true))
            ->when($this->filterStatus === 'not_checked', fn ($q) => $q->where('is_bot', false)->whereNull('user_id')->whereNull('vatsim_linked'))
            ->when($this->filterStatus === 'not_vatsim', fn ($q) => $q->where('is_bot', false)->whereNull('user_id')->where('vatsim_linked', false))
            ->when(preg_match('/^\d{15,21}$/', $this->filterRole) === 1, fn ($q) => $q->whereJsonContains('role_ids', $this->filterRole))
            ->when($this->filterChecked === 'yes', fn ($q) => $q->whereNotNull('last_synced_at'))
            ->when($this->filterChecked === 'no', fn ($q) => $q->whereNull('last_synced_at'));
    }

    public function render(VatssaDiscordRules $rules): View
    {
        $tab = array_key_exists($this->show, self::TABS) ? $this->show : 'server';
        $term = trim($this->search);
        $members = $this->applyFilters($this->scope($tab))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%' . addcslashes($term, '%_\\') . '%')
                ->orWhere('discord_id', $term)->orWhere('user_id', ctype_digit($term) ? $term : -1)->orWhere('vatsim_cid', ctype_digit($term) ? $term : -1)))
            ->orderBy('name')->paginate(50);

        $history = $this->historyFor === null ? collect() : DB::table('vatssa_discord_member_history')
            ->where('discord_id', $this->historyFor)->orderByDesc('id')->limit(30)->get()
            ->map(fn ($event) => ['when' => Carbon::parse($event->created_at)->format('j M Y H:i'),
                'what' => (Sync::EVENTS[$event->event] ?? $event->event) . ($event->detail ? ': ' . $event->detail : '')]);

        $roleNames = DB::table('vatssa_discord_guild_roles')->pluck('name', 'discord_role_id')->mapWithKeys(fn ($n, $id) => ['r' . $id => $n]);

        return view('livewire.vatssa-discord-members', [
            'members' => $members,
            'roleFilters' => collect($rules->managed())->map(fn ($id) => ['id' => (string) $id, 'name' => $roleNames->get('r' . $id) ?? (string) $id])->sortBy('name')->values(),
            'filtered' => trim($this->search . $this->filterName . $this->filterCid . $this->filterStatus . $this->filterRole . $this->filterChecked) !== '',
            'tabs' => self::TABS,
            'active' => $tab,
            'counts' => collect(self::TABS)->map(fn ($label, $key) => $this->scope($key)->count()),
            'managed' => $rules->managed(),
            'roleNames' => $roleNames,
            'history' => $history,
            'historyName' => $this->historyFor ? DB::table('vatssa_discord_members')->where('discord_id', $this->historyFor)->value('name') ?? $this->historyFor : null,
        ]);
    }
}
