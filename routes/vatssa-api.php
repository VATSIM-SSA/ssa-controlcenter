<?php

use App\Http\Controllers\VatssaDiscordSyncController;
use App\Http\Controllers\VatssaGroupController;
use App\Http\Middleware\VatssaDiscordBotToken;
use Illuminate\Support\Facades\Route;

/*
| VATSSA API routes for the Discord role sync bot. Loaded by VatssaServiceProvider
| under /api with its own rate limit (throttle:600,1,vatssa-discord-bot) and
| bindings, not upstream's api group. Every route needs the bot's own token
| (VATSSA_DISCORD_BOT_TOKEN); a CC API key opens none of them.
*/

Route::middleware(VatssaDiscordBotToken::class)->group(function () {
    Route::post('/vatssa/discord-queue/claim', [VatssaDiscordSyncController::class, 'claim'])
        ->name('api.vatssa.discord-queue.claim');
    Route::post('/vatssa/discord-queue/{cid}/result', [VatssaDiscordSyncController::class, 'result'])
        ->whereNumber('cid')->name('api.vatssa.discord-queue.result');
    Route::post('/vatssa/discord-members', [VatssaDiscordSyncController::class, 'members'])
        ->name('api.vatssa.discord-members');
    Route::post('/vatssa/vatsim-facts', [VatssaDiscordSyncController::class, 'facts'])
        ->name('api.vatssa.vatsim-facts');
});

// Groups for any CC API key (read-only is enough), like upstream's /api/users.
Route::middleware('api-token')->group(function () {
    Route::get('/vatssa/groups', [VatssaGroupController::class, 'apiIndex'])->name('api.vatssa.groups.index');
    Route::get('/vatssa/members/{cid}', [VatssaGroupController::class, 'apiMember'])->whereNumber('cid')->name('api.vatssa.members.show');
});
