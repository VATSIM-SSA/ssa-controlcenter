@extends('layouts.app')

@section('title', $title)

@section('content')

@include('vatssa.website.partials.notices')

<div class="row">
    <div class="col-xl-4 col-md-12 mb-4">
        <div class="card shadow">
            <div class="card-header bg-primary py-3">
                <h6 class="m-0 fw-bold text-white">Upload a file</h6>
            </div>
            <div class="card-body">
                <form action="{{ route('vatssa.website.' . $area . '.upload') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="file">File</label>
                        <input type="file" class="form-control @error('file') is-invalid @enderror" id="file" name="file" required>
                        <div class="form-text">{{ strtoupper(implode(', ', $types)) }}. Up to {{ $maxMb }} MB.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="folder">Folder <span class="text-muted">(optional)</span></label>
                        <input type="text" class="form-control @error('folder') is-invalid @enderror" id="folder" name="folder" value="{{ old('folder') }}" maxlength="60">
                    </div>
                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="replace" name="replace" value="1">
                        <label class="form-check-label" for="replace">Replace a file with the same name (the old one is kept in the archive)</label>
                    </div>
                    <p class="fs-sm text-muted">
                        The file keeps its own name in lowercase with hyphens: <em>Code of Conduct 2026.pdf</em> becomes <code>code-of-conduct-2026.pdf</code>. Name it well before uploading.
                        @if($public)
                            Files here are public: anyone with the link can open them.
                        @else
                            Files here are private: only people with access to this page can download them.
                        @endif
                    </p>
                    <button type="submit" class="btn btn-success">Upload</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-8 col-md-12 mb-4">
        <div class="card shadow">
            <div class="card-header bg-primary py-3">
                <h6 class="m-0 fw-bold text-white">Files ({{ count($files) }})</h6>
            </div>
            <div class="card-body">
                @if($files === [])
                    <p class="mb-0">No files yet.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>File</th>
                                    <th class="text-end">Size</th>
                                    <th>Changed (UTC)</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($files as $file)
                                    <tr>
                                        <td class="text-break">
                                            @if($public)
                                                <a href="{{ $publicUrl($file['path']) }}" target="_blank" rel="noopener">{{ $file['path'] }}</a>
                                            @else
                                                <a href="{{ route('vatssa.website.' . $area . '.download', ['path' => $file['path']]) }}">{{ $file['path'] }}</a>
                                            @endif
                                        </td>
                                        <td class="text-end text-nowrap">{{ number_format($file['size'] / 1024, 0) }} KB</td>
                                        <td class="text-nowrap">{{ gmdate('Y-m-d H:i', $file['modified']) }}</td>
                                        <td class="text-end">
                                            <form action="{{ route('vatssa.website.' . $area . '.archive') }}" method="POST" class="d-inline" onsubmit="return confirm({{ \Illuminate\Support\Js::from('Move ' . $file['path'] . ' to the archive?' . ($public ? ' Its public link will stop working.' : '')) }});">
                                                @csrf
                                                <input type="hidden" name="path" value="{{ $file['path'] }}">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary">Archive</button>
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
    </div>
</div>

@endsection
