<?php

use App\Http\Controllers\VatssaDiscordRuleController;
use App\Http\Controllers\VatssaDiscordSyncController;
use App\Http\Controllers\VatssaGroupController;
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
Route::get('/discord/members', [VatssaDiscordSyncController::class, 'index'])->name('vatssa.discord.members');
Route::prefix('discord/rules')->whereNumber('rule')->name('vatssa.discord.rules.')->controller(VatssaDiscordRuleController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/new', 'create')->name('create');
    Route::get('/{rule}', 'edit')->name('edit');
});

// Groups: named lists of people by VATSIM ID. Rights in VatssaGroup.
Route::prefix('groups')->whereNumber('group')->name('vatssa.groups.')->controller(VatssaGroupController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
    Route::get('/{group}', 'show')->name('show');
    Route::patch('/{group}', 'update')->name('update');
    Route::delete('/{group}', 'destroy')->name('destroy');
    Route::post('/{group}/members', 'addMembers')->name('members.add');
    Route::post('/{group}/members/remove', 'removeMembers')->name('members.remove');
    Route::post('/{group}/managers', 'addManager')->name('managers.add');
    Route::post('/{group}/managers/{manager}/remove', 'removeManager')->name('managers.remove');
    Route::post('/{group}/archive', 'archive')->name('archive');
});
