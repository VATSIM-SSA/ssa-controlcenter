<?php

use App\Http\Controllers\API\BookingController;
use App\Http\Controllers\API\PositionController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\CalendarFeedController;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

// Read-only iCalendar feed. Calendar clients cannot log in, so the token in the URL is the credential.
Route::get('/calendar/{token}.ics', [CalendarFeedController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{48}')
    ->withoutMiddleware('throttle:60,1')
    ->middleware('throttle:calendar-feed')
    ->name('calendar.feed');

Route::group(['middleware' => ['api-token:edit']], function () {
    Route::post('/bookings/create', [BookingController::class, 'store'])->name('api.booking.store');
    Route::patch('/bookings/{booking}', [BookingController::class, 'update'])->name('api.booking.update');
    Route::delete('/bookings/{booking}', [BookingController::class, 'destroy'])->name('api.booking.destroy');
});

Route::group(['middleware' => ['api-token']], function () {
    Route::get('/bookings', [BookingController::class, 'index'])->name('api.booking.index');
    Route::get('/bookings/{booking}', [BookingController::class, 'show'])->name('api.booking.show');
    Route::get('/positions', [PositionController::class, 'index'])->name('api.positions.index');

    Route::get('/users', [UserController::class, 'index'])->name('api.users.index');
});
