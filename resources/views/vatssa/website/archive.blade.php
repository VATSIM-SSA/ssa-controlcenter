@extends('layouts.app')

@section('title', 'Archive')

@section('content')

@include('vatssa.website.partials.notices')

<div class="card shadow mb-4">
    <div class="card-header bg-primary py-3">
        <h6 class="m-0 fw-bold text-white">Private archive ({{ count($files) }} files)</h6>
    </div>
    <div class="card-body">
        <p>
            Every file that was replaced or archived, and every earlier version of the website lists. Nothing here is public.
            To put something back, download it and upload it again on the right page.
            Deleting here is permanent.
        </p>
        <p class="fs-sm text-muted">
            Files sit under <code>bucket/original name/</code>, named <code>date-time__original name</code> (UTC).
            Earlier lists are under <code>json/page/</code>.
        </p>

        @if($files === [])
            <p class="mb-0">The archive is empty.</p>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th class="text-end">Size</th>
                            <th>Archived (UTC)</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($files as $file)
                            <tr>
                                <td class="text-break"><a href="{{ route('vatssa.website.archive.download', ['path' => $file['path']]) }}">{{ $file['path'] }}</a></td>
                                <td class="text-end text-nowrap">{{ number_format($file['size'] / 1024, 0) }} KB</td>
                                <td class="text-nowrap">{{ gmdate('Y-m-d H:i', $file['modified']) }}</td>
                                <td class="text-end">
                                    <form action="{{ route('vatssa.website.archive.delete') }}" method="POST" class="d-inline-flex align-items-center gap-2">
                                        @csrf
                                        <input type="hidden" name="path" value="{{ $file['path'] }}">
                                        <label class="fs-sm text-nowrap"><input type="checkbox" class="form-check-input" name="confirm" value="1"> sure</label>
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete for good</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

@endsection
