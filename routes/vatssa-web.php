<?php

use App\Http\Controllers\Vatssa\ActionLogController;
use App\Http\Controllers\Vatssa\AvailabilityController;
use App\Http\Controllers\Vatssa\ExamController;
use App\Http\Controllers\Vatssa\InternalNoteController;
use App\Http\Controllers\Vatssa\RequestActionController;
use App\Http\Controllers\Vatssa\SettingsController;
use App\Http\Controllers\Vatssa\TaskEditController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| VATSSA web routes
|--------------------------------------------------------------------------
|
| Loaded by VatssaServiceProvider with the same middleware upstream's own
| authenticated pages use, so `routes/web.php` stays verbatim and can never
| conflict.
|
| Both pages exist for the same reason: values that change more often than the
| code should not need a deploy to change.
|
*/

/*
| Requests: edit the text, move the desk, reopen a closed one, or raise one that
| is not about a training at all. Upstream can do none of these -- see
| TaskEditController for why each one pushes work back into Discord.
*/
Route::middleware(['web', 'auth', 'activity', 'suspended'])
    ->prefix('vatssa/requests')
    ->name('vatssa.requests.')
    ->group(function () {
        Route::post('/', [TaskEditController::class, 'store'])->name('store');
        Route::patch('/{task}', [TaskEditController::class, 'update'])->name('update');
        Route::post('/{task}/reopen', [TaskEditController::class, 'reopen'])->name('reopen');
        // Do the thing and close the request together, so a paused
        // training and an open request cannot disagree.
        Route::post('/{task}/pause/{mode}', [RequestActionController::class, 'pause'])
            ->whereIn('mode', ['pause', 'resume'])->name('pause');
    });

/*
| Practical exams.
|
| One route per HANDOFF, not per field. Each moves the stage exactly one step
| and each is gated on the party whose turn it is -- the events team cannot
| confirm an examiner's slot, an examiner cannot clear the calendar, and the
| training manager does not pick the date. See ExamPolicy.
|
| The seven-day rule lives in the model rather than here: slots inside the
| window are never offered, so it cannot be broken by somebody being helpful.
*/
Route::middleware(['web', 'auth', 'activity', 'suspended'])
    ->prefix('vatssa/exams')
    ->name('vatssa.exams.')
    ->group(function () {
        Route::get('/', [ExamController::class, 'index'])->name('index');
        Route::get('/{exam}', [ExamController::class, 'show'])->name('show');

        Route::post('/training/{training}', [ExamController::class, 'store'])->name('store');
        Route::post('/{exam}/authorise', [ExamController::class, 'authorise'])->name('authorise');
        Route::post('/{exam}/submit', [ExamController::class, 'submitAvailability'])->name('submit');
        Route::post('/{exam}/clear', [ExamController::class, 'clear'])->name('clear');
        Route::post('/{exam}/confirm', [ExamController::class, 'confirm'])->name('confirm');
        Route::post('/{exam}/publish', [ExamController::class, 'publish'])->name('publish');
        Route::post('/{exam}/cancel', [ExamController::class, 'cancel'])->name('cancel');
    });

/*
| Availability.
|
| The first pages built on Tailwind rather than Bootstrap. They use
| layouts/vatssa.blade.php, which loads neither app.scss nor the upstream
| chrome -- so nothing here can conflict with an upstream release, and the
| whole experiment reverts by deleting a handful of added files.
|
| No permission gate: everybody is asked when they are free at some point,
| and a scheduling tool the people being scheduled cannot open is a
| scheduling tool nobody uses.
*/
Route::middleware(['web', 'auth', 'activity', 'suspended'])
    ->prefix('vatssa/availability')
    ->group(function () {
        Route::get('/', [AvailabilityController::class, 'index'])->name('vatssa.availability');
        // Throttled. Creating a poll is open to every member on purpose --
        // anybody may need to find a time -- and open plus unbounded is how a
        // table fills with junk nobody can sort through.
        Route::post('/', [AvailabilityController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('vatssa.availability.store');
        Route::get('/{poll}', [AvailabilityController::class, 'show'])->name('vatssa.availability.show');
    });

/*
| The automation log. A division report rather than an admin page, so it sits
| beside the other reports and uses the permission coordinators already hold.
*/
Route::middleware(['web', 'auth', 'activity', 'suspended'])
    ->get('/vatssa/automation', [ActionLogController::class, 'index'])
    ->name('vatssa.action-log');

/*
| Internal notes. Not under admin/ -- they are written from a member profile and
| from a training, which is where the context is. Each action authorises on its
| own SCOPE permission, so a training-note permission can never delete a member
| note that happened to be listed nearby.
*/
Route::middleware(['web', 'auth', 'activity', 'suspended'])
    ->prefix('vatssa/notes')
    ->name('vatssa.notes.')
    ->group(function () {
        Route::post('/user/{user}', [InternalNoteController::class, 'storeUserNote'])->name('user');
        Route::post('/training/{training}', [InternalNoteController::class, 'storeTrainingNote'])->name('training');
        Route::delete('/{note}', [InternalNoteController::class, 'destroy'])->name('destroy');
    });

Route::middleware(['web', 'auth', 'activity', 'suspended'])
    ->prefix('admin/vatssa')
    ->name('vatssa.admin.')
    ->group(function () {
        Route::get('/templates', [SettingsController::class, 'templates'])->name('templates');
        Route::patch('/templates/{template}', [SettingsController::class, 'updateTemplate'])->name('templates.update');

        Route::get('/mentorship', [SettingsController::class, 'mentorship'])->name('mentorship');
        Route::post('/mentorship', [SettingsController::class, 'updateMentorship'])->name('mentorship.update');

        Route::get('/routing', [SettingsController::class, 'routing'])->name('routing');
        Route::post('/routing', [SettingsController::class, 'updateRouting'])->name('routing.update');

        Route::get('/moodle-courses', [SettingsController::class, 'courses'])->name('courses');
        Route::post('/moodle-courses', [SettingsController::class, 'updateCourses'])->name('courses.update');
    });
