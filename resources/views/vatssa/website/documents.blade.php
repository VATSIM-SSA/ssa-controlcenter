@extends('layouts.app')

@section('title', $title)

@section('content')

@include('vatssa.website.partials.notices')

<form action="{{ route('vatssa.website.' . $page . '.save') }}" method="POST" enctype="multipart/form-data">
    @csrf
    <input type="hidden" name="version" value="{{ $version }}">

    <div class="card shadow mb-4">
        <div class="card-header bg-primary py-3">
            <h6 class="m-0 fw-bold text-white">{{ $title }} on vatssa.com</h6>
        </div>
        <div class="card-body">
            <p>
                One row per PDF, shown on the website in order.
                To add one, fill in an empty row at the bottom and choose the PDF.
                To publish a new version, choose the new PDF on its row: it replaces the old file under the same name, so existing links keep working, and the old version is kept in the private archive.
                <strong>Archive</strong> takes an entry off the website and moves its file to the private archive: its public link stops working, but nothing is lost.
            </p>
            <p class="fs-sm text-muted">
                File names: the PDF keeps its own name in lowercase with hyphens, so name it before uploading.
                @if($page === 'minutes')
                    Use <code>meeting-type-yyyy-mm-dd.pdf</code>, for example <code>staff-meeting-2026-11-12.pdf</code> or <code>town-hall-2026-08-23.pdf</code>.
                @else
                    Use the policy's name without a version or date, for example <code>rva-policy.pdf</code>: new versions replace it under the same name, and earlier versions stay in the archive.
                @endif
            </p>
            @if($page === 'minutes')
                <p>Use the meeting date as the description, like <em>8 September 2026</em>. Only upload the public version.</p>
            @endif

            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th style="width: 6rem">Order</th>
                            <th>Title</th>
                            <th>{{ $page === 'minutes' ? 'Meeting date' : 'Description' }}</th>
                            <th>PDF</th>
                            <th class="text-center">Archive</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $i => $row)
                            <tr>
                                <td>
                                    <input type="hidden" name="rows[{{ $i }}][id]" value="{{ $row['id'] }}">
                                    <input type="number" class="form-control form-control-sm" name="rows[{{ $i }}][order]" value="{{ old("rows.$i.order", $row['order']) }}" min="0">
                                </td>
                                <td><input type="text" class="form-control form-control-sm @error("rows.$i.title") is-invalid @enderror" name="rows[{{ $i }}][title]" value="{{ old("rows.$i.title", $row['title']) }}" maxlength="150"></td>
                                <td><input type="text" class="form-control form-control-sm @error("rows.$i.description") is-invalid @enderror" name="rows[{{ $i }}][description]" value="{{ old("rows.$i.description", $row['description']) }}" maxlength="300"></td>
                                <td>
                                    {{-- Only https links: the JSON can be edited outside CC. --}}
                                    @if(Str::startsWith($row['url'], ['https://', config('vatssa-website.files_url')]))
                                        <a href="{{ $row['url'] }}" target="_blank" rel="noopener" class="d-block fs-sm text-break">{{ $row['id'] }}</a>
                                    @endif
                                    <input type="file" class="form-control form-control-sm @error("rows.$i.file") is-invalid @enderror" name="rows[{{ $i }}][file]" accept="application/pdf" aria-label="{{ $row['url'] !== '' ? 'Replace PDF' : 'PDF' }}">
                                </td>
                                <td class="text-center"><input type="checkbox" class="form-check-input" name="rows[{{ $i }}][delete]" value="1" @checked(old("rows.$i.delete")) aria-label="Archive this entry"></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <button type="submit" class="btn btn-success">Save {{ Str::lower($title) }}</button>
        </div>
    </div>
</form>

@endsection
