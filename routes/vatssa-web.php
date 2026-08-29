<?php

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
