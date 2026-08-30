<?php

namespace App\Http\Controllers\Vatssa;

use App\Http\Controllers\Controller;
use App\Models\Vatssa\ActionLog;
use Illuminate\View\View;

/**
 * VATSSA: what the automation has been doing.
 *
 * There was no answer to that question anywhere. Effects landed in the database
 * and reasons lived in a container log nobody reads, so the only way to notice
 * a wrong decision was for somebody to complain about its consequence weeks
 * later.
 *
 * Defaults to WARNINGS -- things the system noticed and deliberately did not
 * act on. Those are the rows worth a person's attention; the successful actions
 * are there for when you need them and not before.
 */
class ActionLogController extends Controller
{
    public function index(): View
    {
        // fir.management.reports.view, not tasks.overview: this is a division
        // report, and it is the permission coordinators and the ATC training
        // manager already hold to work the queue.
        $this->authorize('fir.management.reports.view');

        // Whitelisted, not passed through. An unrecognised level would
        // silently match nothing and read as "all clear", which is the one
        // wrong answer this page must never give.
        $level = in_array(request('level'), ['info', 'warning', 'all'], true)
            ? request('level')
            : 'warning';

        $action = request('action');

        $entries = ActionLog::query()
            ->when($level !== 'all', fn ($q) => $q->where('level', $level))
            ->when($action, fn ($q) => $q->where('action', $action))
            ->with('training', 'user')
            ->latest()
            ->limit(300)
            ->get();

        return view('vatssa.action-log', [
            'entries' => $entries,
            'level' => $level,
            'action' => $action,
            // Only the kinds that actually occur, so the filter never offers a
            // choice that returns nothing.
            'kinds' => ActionLog::query()->distinct()->orderBy('action')->pluck('action'),
            'openWarnings' => ActionLog::where('level', ActionLog::WARNING)
                ->where('created_at', '>=', now()->subDays(30))->count(),
        ]);
    }
}
