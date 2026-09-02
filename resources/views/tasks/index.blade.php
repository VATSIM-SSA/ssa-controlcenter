@extends('layouts.app')

@section('title', 'Tasks')
@section('title-flex')
    {{--
        VATSSA: two questions, not one row of tabs.

          which desk   -- yours, one you sit at, every desk, or what you sent
          which state  -- pending or archived

        Upstream mixed them into a single filter, which cannot express "the
        archive of the S2 desk". Both live in the query string, so any
        combination is a shareable link.
    --}}
    <div class="d-flex flex-wrap gap-3 align-items-center">
        <div>
            <i class="fas fa-inbox"></i>&nbsp;Desk:&nbsp;
            <a class="btn btn-sm {{ $desk === 'mine' ? 'btn-primary' : 'btn-outline-primary' }}"
               href="{{ route('tasks', ['state' => $state]) }}">Mine</a>

            @foreach($myDesks as $myDesk)
                @php
                    $key = $myDesk['tier'] . ($myDesk['rating_id'] ? ':' . $myDesk['rating_id'] : '');
                    $name = \App\Models\Vatssa\RequestTarget::label($myDesk['tier']);
                    $rating = $myDesk['rating_id'] ? $ratings->firstWhere('id', $myDesk['rating_id']) : null;
                @endphp
                <a class="btn btn-sm {{ $desk === $key ? 'btn-primary' : 'btn-outline-primary' }}"
                   href="{{ route('tasks', ['desk' => $key, 'state' => $state]) }}">
                    {{ $rating ? $rating->name . ' pipeline' : $name }}
                </a>
            @endforeach

            @if($canSeeAll)
                <a class="btn btn-sm {{ $desk === 'all' ? 'btn-primary' : 'btn-outline-primary' }}"
                   href="{{ route('tasks', ['desk' => 'all', 'state' => $state]) }}">All desks</a>
            @endif

            <a class="btn btn-sm {{ $desk === 'sent' ? 'btn-primary' : 'btn-outline-primary' }}"
               href="{{ route('tasks', ['desk' => 'sent', 'state' => $state]) }}">Sent by you</a>
        </div>

        @can('create', \App\Models\Task::class)
            <div>
                <button class="btn btn-sm btn-light" type="button"
                        data-bs-toggle="modal" data-bs-target="#vatssaNewRequest">
                    <i class="fas fa-plus"></i> Raise a request
                </button>
            </div>
        @endcan

        <div>
            <i class="fas fa-filter"></i>&nbsp;
            <a class="btn btn-sm {{ $state === 'pending' ? 'btn-primary' : 'btn-outline-primary' }}"
               href="{{ route('tasks', ['desk' => $desk, 'state' => 'pending']) }}">Pending</a>
            <a class="btn btn-sm {{ $state === 'archived' ? 'btn-primary' : 'btn-outline-primary' }}"
               href="{{ route('tasks', ['desk' => $desk, 'state' => 'archived']) }}">Archived</a>
        </div>
    </div>
@endsection

@section('content')

<div class="row">
    <div class="col-xl-12 col-md-12 mb-12">
        <div class="card shadow mb-4">
            @if($tasks->count())
                <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 fw-bold text-white">
                        {{ $state === 'archived' ? 'Archived requests' : 'Open requests' }}
                    </h6>
                    <span class="badge bg-light text-dark">{{ $tasks->count() }}</span>
                </div>
            @endif
            <div class="card-body p-0">
                <div class="table-responsive">

                    @if($tasks->count())

                        <table class="table table-striped table-sm table-hover table-leftpadded mb-0" width="100%" cellspacing="0">
                            <thead class="table-light">
                                <tr>
                                    <th>{{ $state === 'archived' ? 'Closed' : 'Created' }}</th>
                                    <th>Subject</th>
                                    <th>Request</th>
                                    {{-- The desk, never the assignee. A request
                                         belongs to a desk; the assignee column
                                         exists because it is NOT NULL and is
                                         deliberately not shown anywhere. --}}
                                    <th>Desk</th>
                                    <th>Raised by</th>
                                    <th>{{ $state === 'archived' ? 'Status' : 'Actions' }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($tasks as $task)
                                    @include('tasks.parts.row', [
                                        'task' => $task,
                                        'state' => $state,
                                        'desk' => $desk,
                                    ])
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <div class="text-center pt-4 pb-4">
                            <i class="fas fa-umbrella-beach" style="font-size: 5rem;"></i>
                            <p class="pt-4 fs-5">
                                @if($state === 'archived')
                                    Nothing archived here yet
                                @elseif($desk === 'sent')
                                    You have not raised any requests
                                @elseif($desk === 'mine' && $myDesks->isEmpty())
                                    You are not on any request desk
                                @else
                                    Nothing open on this desk
                                @endif
                            </p>
                        </div>
                    @endif

                </div>
            </div>
        </div>
    </div>
</div>

{{-- Inside the section: anything a child view emits outside one is discarded,
     so a modal placed after @endsection simply never renders. --}}
@can('create', \App\Models\Task::class)
    @include('vatssa.parts.new-request')
@endcan

@endsection
