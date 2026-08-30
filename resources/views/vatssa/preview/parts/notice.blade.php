{{--
    Said on every preview page, because somebody will find one of these by a
    shared link and think the migration has happened.
--}}
<div class="rounded-xl border border-dashed border-warn/50 bg-warn-wash px-4 py-3 text-sm
 text-warn">
    <p>
        <strong>This is a preview.</strong> A parallel copy showing what Control Center would look
        like migrated to Tailwind. It reads real data and changes nothing — the
        <a href="{{ route('dashboard') }}" class="underline underline-offset-2">real pages</a>
        are untouched.
    </p>
    <p class="mt-1 text-xs opacity-80">
        Deleting <code>resources/views/vatssa/preview/</code>, its controller and its route group
        reverts the experiment completely.
    </p>
</div>
