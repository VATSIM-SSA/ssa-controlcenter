{{--
    VATSSA: the Tailwind shell.

    ## Why a second layout rather than restyling the first

    `layouts/app.blade.php` is upstream's, and so are the 554 blades that
    extend it. Restyling those means a merge conflict on every one, for ever,
    maintained by one person. This layout is an ADDED file: the pages that use
    it are ours, they conflict with nothing, and deleting this file plus the
    vite entry reverts the entire experiment.

    ## What it does NOT load

    `app.scss`, and therefore Bootstrap. That is the point -- these pages get
    unprefixed Tailwind and no cascade fights. It also means nothing here can
    borrow a Bootstrap class by accident, which is the failure mode that turns
    a migration into a hybrid nobody can style.

    Expects: $title. Yields: content. Optional: sidebar-extra, head, js.
--}}
<!DOCTYPE html>
<html lang="en"
      data-user-theme="{{ Auth::check() ? Auth::user()->setting_theme ?? 'system' : 'system' }}"
      class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', $title ?? 'Control Center') · {{ config('app.owner_name', 'VATSSA') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700&display=swap"
          rel="stylesheet">

    @vite(['resources/css/vatssa.css'])
    <style>[x-cloak] { display: none !important; }</style>
    @yield('head')
</head>

<body class="h-full bg-page text-ink antialiased">

<div class="flex min-h-full" x-data="{ nav: false }" @keydown.escape.window="nav = false">

    {{-- The scrim. Only below lg, and only while the drawer is open: a
         click-anywhere-to-close target is the difference between a drawer and
         a trap on a phone. --}}
    <div x-show="nav" x-cloak @click="nav = false"
         class="fixed inset-0 z-30 bg-sidebar/40 lg:hidden"></div>

    {{-- Sidebar. Fixed on desktop, a drawer below lg. --}}
    <aside class="fixed inset-y-0 left-0 z-40 w-64 shrink-0 overflow-y-auto border-r border-line
 bg-card transition-transform lg:static lg:translate-x-0"
           :class="nav ? 'translate-x-0' : '-translate-x-full'">

{{-- The real wordmark, on a dark block.

             public/images/logos/vatssa.svg is filled #F8F3F0 -- it is drawn for
             a dark ground and disappears on a white sidebar. Rather than
             recolouring somebody else's logo, the block behind it is dark in
             both themes. That is also the only place on these pages where the
             brand gradient appears, which is what keeps it a brand mark rather
             than decoration. --}}
        <a href="{{ route('dashboard') }}"
           class="m-3 flex h-14 items-center justify-center rounded-xl bg-sidebar px-5
 transition-opacity hover:opacity-90">
            <img src="{{ asset('images/logos/vatssa.svg') }}" alt="VATSSA"
                 class="h-7 w-auto">
        </a>

        <p class="px-5 pb-4 text-[11px] font-medium uppercase tracking-widest text-ink-faint">
            Control Center
        </p>

        <nav class="space-y-6 px-3 pb-8">
            @include('vatssa.parts.nav')
            @yield('sidebar-extra')
        </nav>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">

        {{-- Topbar. Deliberately thin: it holds identity and nothing else,
             because a second row of navigation is how a dashboard starts
             feeling like an intranet. --}}
        <header class="sticky top-0 z-30 flex h-16 items-center gap-4 border-b border-line
 bg-page/80 px-5 backdrop-blur">

            <button type="button" @click="nav = ! nav"
                    class="-ml-1 rounded-lg p-2 text-ink-soft hover:bg-card-header lg:hidden"
                    aria-label="Open navigation">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>

            <div class="min-w-0 flex-1">
                <h1 class="truncate text-[15px] font-semibold tracking-tight">
                    @yield('title', $title ?? '')
                </h1>
            </div>

            @auth
                <a href="{{ route('user.show', Auth::id()) }}"
                   class="flex items-center gap-2.5 rounded-lg py-1.5 pl-2 pr-3 text-sm
 hover:bg-card-header">
                    <span class="grid h-7 w-7 place-items-center rounded-full bg-line text-[11px]
 font-semibold text-ink-soft">
                        {{ Str::of(Auth::user()->name)->explode(' ')->take(2)->map(fn ($p) => Str::substr($p, 0, 1))->join('') }}
                    </span>
                    <span class="hidden text-ink-soft sm:block">
                        {{ Auth::user()->name }}
                    </span>
                </a>
            @endauth
        </header>

        <main class="mx-auto w-full max-w-7xl flex-1 px-5 py-8">

            {{-- Flash messages. Same three upstream uses, so a redirect from an
                 upstream controller into one of these pages still says what
                 happened. --}}
            @if(session('success'))
                <div class="mb-6 rounded-xl border border-good/40 bg-good-wash px-4 py-3 text-sm
 text-good">
                    {{ session('success') }}
                </div>
            @endif

            @if($errors->any())
                <div class="mb-6 rounded-xl border border-bad/40 bg-bad-wash px-4 py-3 text-sm
 text-bad">
                    @foreach($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            @yield('content')
        </main>

        {{-- The credit line every VATSSA build carries. No privacy link:
             Control Center is behind VATSIM SSO and has no public page, and a
             link to a route that does not exist is worse than none. --}}
        <footer class="px-5 pb-8 pt-4 text-xs text-ink-faint">
            Control Center © {{ date('Y') }} Daniël Schoonraad
        </footer>
    </div>
</div>

{{-- Livewire only. It bundles Alpine, which is what the availability grid
     needs. @fluxScripts is deliberately absent: these pages render no `flux:`
     components, so it would be a second bundle downloaded for nothing. --}}
@livewireScripts
@yield('js')
</body>
</html>
