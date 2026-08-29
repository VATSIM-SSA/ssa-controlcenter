{{--
    VATSSA: one request row.

    THE ASSIGNEE IS NEVER SHOWN. A request belongs to a desk, and everybody at
    that desk can act on it. `assignee_user_id` exists because the column is NOT
    NULL, and displaying it would put one person's name on work that is not
    theirs alone -- which is the ownership confusion the desks were built to
    remove.

    Expects: $task, $state ('pending'|'archived'), $desk
--}}
<tr>
    <td>
        @php $showClosed = $state === 'archived' && $task->closed_at; @endphp
        <span data-bs-toggle="tooltip" data-bs-placement="top" title="{{ $showClosed ? $task->closed_at->toEuropeanDateTime() : $task->created_at->toEuropeanDateTime() }}">
            {{ $showClosed ? $task->closed_at->diffForHumans() : $task->created_at->diffForHumans() }}
        </span>
    </td>

    <td>
        @if($task->subject)
            <a href="{{ route('user.show', $task->subject) }}">{{ $task->subject->name }} ({{ $task->subject->id }})</a>
        @else
            {{-- Not every request is about somebody. "Review the S2 syllabus"
                 is about nobody, and saying so beats an empty cell. --}}
            <span class="text-muted">Not about a member</span>
        @endif
    </td>

    <td>
        <i class="fas {{ $task->type()->getIcon() }}" data-bs-toggle="tooltip" data-bs-placement="top" title="{{ $task->type()->getName() }}"></i>

        @if($task->type()->getLink($task))
            <a href="{{ $task->type()->getLink($task) }}" target="_blank" class="link-offset-1 dotted-underline">{{ $task->type()->getText($task) }}</a>
        @else
            {{ $task->type()->getText($task) }}
        @endif
    </td>

    <td>
        @if($task->vatssa_tier)
            {{-- Looked up here rather than through a relation on upstream's Task
                 model: adding one would have made Task.php a modified file, and
                 a conflict on every release, for a single accessor. --}}
            @php $deskRating = $task->vatssa_rating_id
                ? \App\Models\Rating::find($task->vatssa_rating_id) : null; @endphp
            <span class="badge bg-secondary">
                {{ \App\Models\Vatssa\RequestTarget::label($task->vatssa_tier) }}@if($deskRating)
                    — {{ $deskRating->name }}
                @endif
            </span>
        @else
            {{-- Upstream tasks, and anything created before the desks existed.
                 Shown rather than hidden: a request with no desk is one nobody
                 is collectively responsible for, which is worth noticing. --}}
            <span class="badge bg-light text-dark">No desk</span>
        @endif

        {{-- VATSSA: move it. A request sent to the wrong desk is otherwise
             declined with "ask X instead", and the asker starts again. Only
             for people who can see every desk -- moving work onto a desk you
             cannot see the queue of is how things get lost. --}}
        @can('tasks.overview')
            <form method="POST" action="{{ route('vatssa.requests.update', $task) }}"
                  class="d-flex gap-1 mt-1">
                @csrf
                @method('PATCH')
                <input type="hidden" name="message" value="{{ $task->message }}">
                <select name="vatssa_tier" class="form-select form-select-sm" style="max-width: 11rem">
                    @foreach(\App\Models\Vatssa\RequestTarget::TIERS as $tierKey => $tier)
                        <option value="{{ $tierKey }}" @selected($task->vatssa_tier === $tierKey)>
                            {{ $tier['label'] }}
                        </option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-sm btn-outline-secondary">Move</button>
            </form>
        @endcan
    </td>

    <td>
        @isset($task->creator)
            <a href="{{ route('user.show', $task->creator) }}">{{ $task->creator->name }} ({{ $task->creator->id }})</a>
        @else
            System
        @endisset
    </td>

    <td>
        @if($state === 'pending')
            <div class="btn-toolbar" role="toolbar" aria-label="Task actions">
                <div class="btn-group">
                    @if($task->type()->isApproval())
                        <a href="{{ route('task.complete', $task) }}" class="btn btn-sm btn-outline-success text-center" role="button"><i class="fas fa-check"></i> Approve</a>
                    @else
                        <a href="{{ route('task.complete', $task) }}" class="btn btn-sm btn-outline-success text-center" role="button"><i class="fas fa-check"></i> Complete</a>
                    @endif

                    <a href="{{ route('task.decline', $task) }}" class="btn btn-sm btn-outline-danger text-decoration-none ms-1 text-center" title="Decline task" role="button" onclick="return confirm('Are you sure you want to decline this task?')">
                        <i class="fas fa-xmark"></i><span class="d-block d-md-none">Decline</span>
                    </a>
                </div>
            </div>
        @else
            @if($task->status == \App\Helpers\TaskStatus::COMPLETED)
                <span class="badge bg-success">{{ Str::title(\App\Helpers\TaskStatus::COMPLETED->name) }}</span>
            @elseif($task->status == \App\Helpers\TaskStatus::DECLINED)
                <span class="badge bg-danger">{{ Str::title(\App\Helpers\TaskStatus::DECLINED->name) }}</span>
            @elseif($task->status == \App\Helpers\TaskStatus::PENDING)
                <span class="badge bg-warning">{{ Str::title(\App\Helpers\TaskStatus::PENDING->name) }}</span>
            @endif

            {{-- VATSSA: reopen. A request closed by mistake, or one that turned
                 out not to be finished, otherwise becomes a second request --
                 and the history splits across the two. --}}
            @can('update', \App\Models\Task::class)
                <form method="POST" action="{{ route('vatssa.requests.reopen', $task) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-link btn-sm p-0 align-baseline">reopen</button>
                </form>
            @endcan
        @endif
    </td>
</tr>
