<?php

use App\Http\Controllers\VatssaDiscordSyncController;
use App\Http\Controllers\VatssaTheoryController;
use App\Http\Controllers\VatssaTrainingFlagController;
use App\Services\VatssaTrainingFlags;
use Illuminate\Support\Facades\Route;

/*
| VATSSA routes. Loaded by VatssaServiceProvider with the same middleware as
| the authenticated group in routes/web.php: web, auth, activity, suspended.
*/

Route::post('/training/{training}/action/theory', [VatssaTheoryController::class, 'update'])->name('training.action.theory');
foreach (array_keys(VatssaTrainingFlags::FLAGS) as $flag) {
    Route::post("/training/{training}/action/{$flag}", [VatssaTrainingFlagController::class, 'update'])
        ->defaults('flag', $flag)
        ->name("training.action.{$flag}");
}
Route::post('/user/{user}/discord-sync', [VatssaDiscordSyncController::class, 'store'])
    ->middleware('throttle:30,1,vatssa-discord-button')->name('vatssa.user.discord-sync');
