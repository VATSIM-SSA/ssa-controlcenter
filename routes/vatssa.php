<?php

use App\Http\Controllers\Vatssa\BridgeController;
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
| The consolidated roster. Read-only, no secrets, and the same list the public
| website already shows -- so it is open, like upstream's own /api/bookings and
| /api/positions. Cached; see config/vatssa.php.
*/
Route::get('/roster', [RosterController::class, 'index'])->name('vatssa.roster');

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
Route::middleware(['vatssa-bridge', 'throttle:120,1'])
    ->prefix('bridge')
    ->name('vatssa.bridge.')
    ->group(function () {
        Route::post('/users/{user}/platforms', [BridgeController::class, 'platforms'])->name('platforms');
        Route::post('/users/{user}/theory-attempts', [BridgeController::class, 'theoryAttempt'])->name('theory');
        Route::post('/trainings/{training}/messages', [BridgeController::class, 'logMessage'])->name('messages');
        Route::patch('/trainings/{training}/status', [BridgeController::class, 'setStatus'])->name('status');
        Route::get('/templates', [BridgeController::class, 'templates'])->name('templates');
        Route::get('/moodle-courses', [BridgeController::class, 'courses'])->name('courses');
    });
