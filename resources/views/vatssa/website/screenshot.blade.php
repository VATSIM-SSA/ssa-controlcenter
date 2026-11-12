@extends('layouts.app')

@section('title', 'Screenshot winner')

@section('content')

@include('vatssa.website.partials.notices')

<div class="row">
    <div class="col-xl-6 col-md-12 mb-4">
        <div class="card shadow">
            <div class="card-header bg-primary py-3">
                <h6 class="m-0 fw-bold text-white">On the homepage now</h6>
            </div>
            <div class="card-body">
                @if($winner && Str::startsWith($winner['link'], 'https://'))
                    <img src="{{ $winner['link'] }}" alt="Current screenshot winner" class="img-fluid rounded mb-2">
                    <p class="mb-0"><strong>{{ $winner['name'] }}</strong> &middot; {{ $winner['month'] }}</p>
                @else
                    <p class="mb-0">No winner is set. The homepage shows its built-in photo.</p>
                @endif
            </div>
        </div>
    </div>

    <div class="col-xl-6 col-md-12 mb-4">
        <div class="card shadow">
            <div class="card-header bg-primary py-3">
                <h6 class="m-0 fw-bold text-white">Set a new winner</h6>
            </div>
            <div class="card-body">
                <form action="{{ route('vatssa.website.screenshot.save') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <input type="hidden" name="version" value="{{ $version }}">

                    <div class="mb-3">
                        <label class="form-label" for="name">Winner's name</label>
                        <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name') }}" maxlength="80" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="cid">Winner's CID</label>
                        <input type="text" inputmode="numeric" class="form-control @error('cid') is-invalid @enderror" id="cid" name="cid" value="{{ old('cid') }}" maxlength="8" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="month">Month</label>
                        <input type="text" class="form-control @error('month') is-invalid @enderror" id="month" name="month" value="{{ old('month', now()->format('M') . now()->format('y')) }}" maxlength="7" required>
                        <div class="form-text">Like Nov26 or Sept26.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="image">Picture</label>
                        <input type="file" class="form-control @error('image') is-invalid @enderror" id="image" name="image" accept="image/jpeg,image/png,image/webp" required>
                        <div class="form-text">JPG, PNG or WebP, landscape, at least 1920 pixels wide, under 8 MB.</div>
                    </div>

                    <p class="fs-sm text-muted">The homepage shows it as "Image Credit: Name - CID". Announce the winner on News yourself.</p>

                    <button type="submit" class="btn btn-success">Publish winner</button>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection
