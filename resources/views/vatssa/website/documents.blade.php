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
                To add one, fill in an empty row at the bottom and choose the PDF; it keeps its file name, so name it well first.
                To publish a new version of an existing one, choose the new PDF on its row: it replaces the old file under the same name, so existing links keep working.
                Removing a row takes it off the website, but the file itself stays, so old links still open.
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
                            <th class="text-center">Remove</th>
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
                                    @if(Str::startsWith($row['url'], 'https://'))
                                        <a href="{{ $row['url'] }}" target="_blank" rel="noopener" class="d-block fs-sm text-break">{{ $row['id'] }}</a>
                                    @endif
                                    <input type="file" class="form-control form-control-sm @error("rows.$i.file") is-invalid @enderror" name="rows[{{ $i }}][file]" accept="application/pdf" aria-label="{{ $row['url'] !== '' ? 'Replace PDF' : 'PDF' }}">
                                </td>
                                <td class="text-center"><input type="checkbox" class="form-check-input" name="rows[{{ $i }}][delete]" value="1" @checked(old("rows.$i.delete")) aria-label="Remove this row"></td>
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
