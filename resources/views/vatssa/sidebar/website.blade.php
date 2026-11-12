{{-- VATSSA: Website content menu, included once from layouts/sidebar.blade.php. --}}
@php($vatssaWebsitePages = \App\Http\Controllers\VatssaWebsiteController::pagesFor(auth()->user()))
@if($vatssaWebsitePages !== [])
    <x-sidebar.section icon="fa-globe" title="Website" :active="Route::is('vatssa.website.*')" id="collapseVatssaWebsite">
        @foreach($vatssaWebsitePages as $key => $title)
            <x-sidebar.item :href="route('vatssa.website.' . $key)" :title="$title" collapse />
        @endforeach
    </x-sidebar.section>
@endif
