{{-- VATSSA: website content notices. The layout already shows success and errors. --}}
@if(session('vatssa_website_warning'))
    <div class="alert alert-warning" role="alert">
        <i class="fas fa-lg fa-exclamation-triangle"></i>&nbsp;{{ session('vatssa_website_warning') }}
    </div>
@endif

@if(!empty($unreadable))
    <div class="alert alert-danger" role="alert">
        The file on the website could not be read (it is not valid JSON), so this form starts empty.
        Saving replaces it completely. The broken version is kept in MinIO under <code>_history</code>.
    </div>
@endif

<p class="text-muted fs-sm">
    <a href="{{ route('vatssa.website.index') }}"><i class="fas fa-arrow-left"></i> All website pages</a>
</p>
