{{-- VATSSA: Groups in the sidebar, for group staff and for anyone managing a group. Included from layouts/sidebar.blade.php. --}}
@if(\App\Models\VatssaGroup::isStaff(auth()->user()) || \App\Models\VatssaGroup::anyManagedBy(auth()->user()))
    <x-sidebar.item :href="route('vatssa.groups.index')" icon="fa-people-group" title="Groups" :active="Route::is('vatssa.groups.*')" />
@endif
@if(auth()->user()->hasPermission('vatssa.discord.members.view'))
    <x-sidebar.item :href="route('vatssa.discord.members')" icon="fa-users-rectangle" title="Discord members" :active="Route::is('vatssa.discord.*')" />
@endif
