<?php

use App\Http\Controllers\Vatssa\BridgeController;
use App\Http\Controllers\Vatssa\MoodleHookController;
use App\Http\Controllers\Vatssa\RosterController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| VATSSA routes
|--------------------------------------------------------------------------
|
| Loaded by VatssaServiceProvider under the `api/vatssa` prefix, so
| `routes/api.php` stays verbatim upstream and can never conflict.
|
| Two groups, two very different audiences.
|
*/

/*
| The consolidated roster. Read-only and open, like upstream's own
| /api/bookings and /api/positions, because it is the list the public website
| already shows. Cached; see config/vatssa.php.
|
| THROTTLED. It was not, and it is the one VATSSA endpoint an outsider can
| reach with no credential at all. Every other route in this file is rate
| limited; this one returning the whole division's roster for free, as fast as
| anybody cares to ask, was an oversight rather than a decision.
|
| 30/minute is far above what the homepage needs -- it caches for five minutes
| -- and far below what makes bulk collection comfortable.
*/
Route::middleware('throttle:30,1')
    ->get('/roster', [RosterController::class, 'index'])
    ->name('vatssa.roster');

/*
| The training pipeline bridge.
|
| The bot reaches this container-to-container over the Docker network and does
| NOT need the public route. Caddy must 403 `/api/vatssa/bridge/*` at the edge;
| the token below is the second lock, not the only one.
|
| Throttled because a bug in the bot -- a poll loop that never sleeps -- should
| cost a 429 rather than the database.
*/
/*
| Moodle's webhook.
|
| PUBLIC, unlike the bridge, because Moodle is on the internet and the bridge
| is deliberately not reachable from it. Its own secret, one endpoint, and the
| only thing it may write is whether somebody has a Moodle account.
|
| Throttled hard. It is the one VATSSA route an outsider can reach, and the
| worst thing a wrong guess should cost is a 429.
*/
Route::middleware('throttle:60,1')
    ->post('/moodle/hook', MoodleHookController::class)
    ->name('vatssa.moodle.hook');

Route::middleware(['vatssa-bridge', 'throttle:120,1'])
    ->prefix('bridge')
    ->name('vatssa.bridge.')
    ->group(function () {
        Route::post('/users/{user}/platforms', [BridgeController::class, 'platforms'])->name('platforms');
        Route::post('/users/{user}/theory-attempts', [BridgeController::class, 'theoryAttempt'])->name('theory');
        Route::post('/trainings/{training}/messages', [BridgeController::class, 'logMessage'])->name('messages');
        Route::patch('/trainings/{training}/status', [BridgeController::class, 'setStatus'])->name('status');
        Route::post('/trainings/{training}/comment', [BridgeController::class, 'comment'])->name('comment');
        Route::post('/trainings/{training}/close', [BridgeController::class, 'close'])->name('close');
        Route::post('/action-log', [BridgeController::class, 'actionLog'])->name('action-log');
        Route::get('/exemptions', [BridgeController::class, 'exemptions'])->name('exemptions');
        Route::get('/templates', [BridgeController::class, 'templates'])->name('templates');
        Route::get('/moodle-courses', [BridgeController::class, 'courses'])->name('courses');
    });
