<tr>
    <td>
        @php $showClosed = in_array($activeFilter, ['archived', 'all-archived']) && $task->closed_at; @endphp
        <span data-bs-toggle="tooltip" data-bs-placement="top" title="{{ $showClosed ? $task->closed_at->toEuropeanDateTime() : $task->created_at->toEuropeanDateTime() }}">
            {{ $showClosed ? $task->closed_at->diffForHumans() : $task->created_at->diffForHumans() }}
        </span>
    </td>
    <td><a href="{{ route('user.show', $task->subject) }}">{{ $task->subject->name }} ({{ $task->subject->id }})</a></td>
    <td>
        <i class="fas {{ $task->type()->getIcon() }}" data-bs-toggle="tooltip" data-bs-placement="top" title="{{ $task->type()->getName() }}"></i>

        @if($task->type()->getLink($task))
            <a href="{{ $task->type()->getLink($task) }}" target="_blank" class="link-offset-1 dotted-underline">{{ $task->type()->getText($task) }}</a>
        @else
            {{ $task->type()->getText($task) }}
        @endif
    </td>
    <td>
        @if($activeFilter == 'sent')
            <a href="{{ route('user.show', $task->assignee) }}">{{ $task->assignee->name }} ({{ $task->assignee->id }})</a>
        @else
            @isset($task->creator)
                <a href="{{ route('user.show', $task->creator) }}">{{ $task->creator->name }} ({{ $task->creator->id }})</a>
            @else
                System
            @endisset
        @endif
    </td>

    @if(in_array($activeFilter, ['all', 'all-archived']))
        {{-- VATSSA: which desk it went to, and who is holding it. The desk is
             the useful half -- "the S2 coordinator" survives a person leaving,
             where a name does not. --}}
        <td>
            @if($task->vatssa_tier)
                <span class="badge bg-secondary">{{ \App\Models\Vatssa\RequestTarget::label($task->vatssa_tier) }}</span>
            @endif
            <a class="d-block small" href="{{ route('user.show', $task->assignee) }}">{{ $task->assignee->name }} ({{ $task->assignee->id }})</a>
        </td>
    @endif

    <td>
        {{-- VATSSA: the overview shows status, not buttons. Those are other
             people's tasks; offering Complete on one you do not own is an
             invitation to a mistake the policy would refuse anyway. --}}
        @if(!in_array($activeFilter, ['sent', 'archived', 'all', 'all-archived']))
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
        @endif
    </td>
</tr>