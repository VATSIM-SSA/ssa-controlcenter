@extends('layouts.app')

@section('title', 'Staff list')

@section('content')

@include('vatssa.website.partials.notices')

<form action="{{ route('vatssa.website.staff.save') }}" method="POST" enctype="multipart/form-data">
    @csrf
    <input type="hidden" name="version" value="{{ $version }}">

    <div class="card shadow mb-4">
        <div class="card-header bg-primary py-3">
            <h6 class="m-0 fw-bold text-white">Staff team page</h6>
        </div>
        <div class="card-body">
            <p>
                One row per person, shown on vatssa.com/about/staff-team grouped by team, in order.
                For a vacant post write <strong>VACANT</strong> as the name and keep the role and email.
                Add someone in an empty row at the bottom. Photos: square PNG, JPG or WebP, under 2 MB. Without one, the homepage shows its built-in photo for that person, if it has one.
            </p>

            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th style="width: 6rem">Order</th>
                            <th>Team</th>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Email</th>
                            <th>CID / code</th>
                            <th>Photo</th>
                            <th class="text-center">Remove</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $i => $row)
                            <tr>
                                <td>
                                    <input type="hidden" name="rows[{{ $i }}][id]" value="{{ old("rows.$i.id", $row['id']) }}">
                                    <input type="number" class="form-control form-control-sm" name="rows[{{ $i }}][order]" value="{{ old("rows.$i.order", $row['order']) }}" min="0">
                                </td>
                                <td><input type="text" class="form-control form-control-sm @error("rows.$i.team") is-invalid @enderror" name="rows[{{ $i }}][team]" value="{{ old("rows.$i.team", $row['team']) }}" maxlength="60" list="vatssa-teams"></td>
                                <td><input type="text" class="form-control form-control-sm @error("rows.$i.name") is-invalid @enderror" name="rows[{{ $i }}][name]" value="{{ old("rows.$i.name", $row['name']) }}" maxlength="80"></td>
                                <td><input type="text" class="form-control form-control-sm @error("rows.$i.role") is-invalid @enderror" name="rows[{{ $i }}][role]" value="{{ old("rows.$i.role", $row['role']) }}" maxlength="80"></td>
                                <td><input type="email" class="form-control form-control-sm @error("rows.$i.email") is-invalid @enderror" name="rows[{{ $i }}][email]" value="{{ old("rows.$i.email", $row['email']) }}" maxlength="120"></td>
                                <td><input type="text" class="form-control form-control-sm @error("rows.$i.cid") is-invalid @enderror" name="rows[{{ $i }}][cid]" value="{{ old("rows.$i.cid", $row['cid']) }}" maxlength="40"></td>
                                <td>
                                    <input type="hidden" name="rows[{{ $i }}][photo]" value="{{ $row['photo'] }}">
                                    @if(Str::startsWith($row['photo'], ['https://', config('vatssa-website.files_url')]))
                                        <img src="{{ $row['photo'] }}" alt="" class="rounded mb-1" style="height: 2.5rem; max-width: 5rem; object-fit: contain">
                                        <label class="d-block fs-sm text-nowrap"><input type="checkbox" class="form-check-input" name="rows[{{ $i }}][remove_photo]" value="1"> remove</label>
                                    @endif
                                    <input type="file" class="form-control form-control-sm @error("rows.$i.photo_file") is-invalid @enderror" name="rows[{{ $i }}][photo_file]" accept="image/png,image/jpeg,image/webp" aria-label="Photo">
                                </td>
                                <td class="text-center"><input type="checkbox" class="form-check-input" name="rows[{{ $i }}][delete]" value="1" @checked(old("rows.$i.delete")) aria-label="Remove this row"></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <datalist id="vatssa-teams">
                @foreach(collect($rows)->pluck('team')->filter()->unique() as $team)
                    <option value="{{ $team }}">
                @endforeach
            </datalist>

            <button type="submit" class="btn btn-success">Save staff list</button>
        </div>
    </div>
</form>

@endsection
