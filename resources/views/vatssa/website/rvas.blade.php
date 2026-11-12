@extends('layouts.app')

@section('title', 'Partner rVAs')

@section('content')

@include('vatssa.website.partials.notices')

<form action="{{ route('vatssa.website.rvas.save') }}" method="POST" enctype="multipart/form-data">
    @csrf
    <input type="hidden" name="version" value="{{ $version }}">

    <div class="card shadow mb-4">
        <div class="card-header bg-primary py-3">
            <h6 class="m-0 fw-bold text-white">Partner rVA page</h6>
        </div>
        <div class="card-body">
            <p>
                One row per airline, shown on vatssa.com/about/partner-rvas in order.
                Links must start with https://. Add an airline in an empty row at the bottom.
                Logos: PNG, JPG or WebP, wide rather than tall, under 2 MB. Without one, the homepage shows its built-in logo for that airline, if it has one.
            </p>

            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th style="width: 6rem">Order</th>
                            <th>Airline</th>
                            <th>Website</th>
                            <th>Sign-up link</th>
                            <th style="width: 6rem">Tier</th>
                            <th>Description</th>
                            <th>Logo</th>
                            <th class="text-center">Remove</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $i => $row)
                            <tr>
                                <td><input type="number" class="form-control form-control-sm" name="rows[{{ $i }}][order]" value="{{ old("rows.$i.order", $row['order']) }}" min="0"></td>
                                <td><input type="text" class="form-control form-control-sm @error("rows.$i.name") is-invalid @enderror" name="rows[{{ $i }}][name]" value="{{ old("rows.$i.name", $row['name']) }}" maxlength="80"></td>
                                <td><input type="url" class="form-control form-control-sm @error("rows.$i.site") is-invalid @enderror" name="rows[{{ $i }}][site]" value="{{ old("rows.$i.site", $row['site']) }}" maxlength="255"></td>
                                <td><input type="url" class="form-control form-control-sm @error("rows.$i.signup") is-invalid @enderror" name="rows[{{ $i }}][signup]" value="{{ old("rows.$i.signup", $row['signup']) }}" maxlength="500"></td>
                                <td>
                                    @php($tier = (string) old("rows.$i.tier", $row['tier']))
                                    <select class="form-select form-select-sm @error("rows.$i.tier") is-invalid @enderror" name="rows[{{ $i }}][tier]">
                                        <option value="" @selected($tier === '')></option>
                                        <option value="1" @selected($tier === '1')>1</option>
                                        <option value="2" @selected($tier === '2')>2</option>
                                    </select>
                                </td>
                                <td><input type="text" class="form-control form-control-sm @error("rows.$i.description") is-invalid @enderror" name="rows[{{ $i }}][description]" value="{{ old("rows.$i.description", $row['description']) }}" maxlength="500"></td>
                                <td>
                                    <input type="hidden" name="rows[{{ $i }}][logo]" value="{{ $row['logo'] }}">
                                    @if(Str::startsWith($row['logo'], ['https://', config('vatssa-website.files_url')]))
                                        <img src="{{ $row['logo'] }}" alt="" class="rounded mb-1" style="height: 2.5rem; max-width: 5rem; object-fit: contain">
                                        <label class="d-block fs-sm text-nowrap"><input type="checkbox" class="form-check-input" name="rows[{{ $i }}][remove_logo]" value="1"> remove</label>
                                    @endif
                                    <input type="file" class="form-control form-control-sm @error("rows.$i.logo_file") is-invalid @enderror" name="rows[{{ $i }}][logo_file]" accept="image/png,image/jpeg,image/webp" aria-label="Logo">
                                </td>
                                <td class="text-center"><input type="checkbox" class="form-check-input" name="rows[{{ $i }}][delete]" value="1" @checked(old("rows.$i.delete")) aria-label="Remove this row"></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <button type="submit" class="btn btn-success">Save rVA list</button>
        </div>
    </div>
</form>

@endsection
