<?php

use App\Http\Controllers\Vatssa\InternalNoteController;
use App\Http\Controllers\Vatssa\TaskEditController;
use App\Http\Controllers\Vatssa\SettingsController;
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
    });

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

        Route::get('/routing', [SettingsController::class, 'routing'])->name('routing');
        Route::post('/routing', [SettingsController::class, 'updateRouting'])->name('routing.update');

        Route::get('/moodle-courses', [SettingsController::class, 'courses'])->name('courses');
        Route::post('/moodle-courses', [SettingsController::class, 'updateCourses'])->name('courses.update');
    });
