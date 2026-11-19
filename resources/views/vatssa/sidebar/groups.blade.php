{{-- VATSSA: Groups in the sidebar, for group staff and for anyone managing a group. Included from layouts/sidebar.blade.php. --}}
@if(\App\Models\VatssaGroup::isStaff(auth()->user()) || \App\Models\VatssaGroup::anyManagedBy(auth()->user()))
    <x-sidebar.item :href="route('vatssa.groups.index')" icon="fa-people-group" title="Groups" :active="Route::is('vatssa.groups.*')" />
@endif
