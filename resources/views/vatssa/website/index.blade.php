@extends('layouts.app')

@section('title', 'Website content')

@section('content')

@if(session('vatssa_website_warning'))
    <div class="alert alert-warning" role="alert">
        <i class="fas fa-lg fa-exclamation-triangle"></i>&nbsp;{{ session('vatssa_website_warning') }}
    </div>
@endif

<div class="row">
    <div class="col-xl-8 col-md-12 mb-4">
        <div class="card shadow">
            <div class="card-header bg-primary py-3">
                <h6 class="m-0 fw-bold text-white">What you can edit</h6>
            </div>
            <div class="card-body">
                <p>Changes go live on vatssa.com as soon as you save. Every save keeps the previous version, so Tech can put it back if something goes wrong.</p>
                <div class="list-group">
                    @foreach($pages as $key => $title)
                        <a href="{{ route('vatssa.website.' . $key) }}" class="list-group-item list-group-item-action">{{ $title }}</a>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>

@endsection
