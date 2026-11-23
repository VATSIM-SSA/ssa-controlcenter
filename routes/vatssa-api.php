<?php

use App\Http\Controllers\VatssaDiscordSyncController;
use App\Http\Controllers\VatssaGroupController;
use App\Http\Middleware\VatssaDiscordBotToken;
use Illuminate\Support\Facades\Route;

/*
| VATSSA API routes for the Discord role sync bot. Loaded by VatssaServiceProvider
| under /api with bindings, not upstream's api group, and its own rate limits. The bot
| makes about two calls per member, so a rule change needs far more than an API client. Every route needs the bot's own token
| (VATSSA_DISCORD_BOT_TOKEN); a CC API key opens none of them.
*/

Route::middleware([VatssaDiscordBotToken::class, 'throttle:3000,1,vatssa-discord-bot'])->group(function () {
    Route::post('/vatssa/discord-queue/claim', [VatssaDiscordSyncController::class, 'claim'])
        ->name('api.vatssa.discord-queue.claim');
    Route::post('/vatssa/discord-queue/{cid}/result', [VatssaDiscordSyncController::class, 'result'])
        ->whereNumber('cid')->name('api.vatssa.discord-queue.result');
    Route::post('/vatssa/discord-members', [VatssaDiscordSyncController::class, 'members'])
        ->name('api.vatssa.discord-members');
    Route::post('/vatssa/discord-roles', [VatssaDiscordSyncController::class, 'roles'])
        ->name('api.vatssa.discord-roles');
    Route::post('/vatssa/discord-guild-roles', [VatssaDiscordSyncController::class, 'guildRoles'])
        ->name('api.vatssa.discord-guild-roles');
});

// Groups for any CC API key (read-only is enough), like upstream's /api/users.
Route::middleware(['throttle:600,1,vatssa-api', 'api-token'])->group(function () {
    Route::get('/vatssa/groups', [VatssaGroupController::class, 'apiIndex'])->name('api.vatssa.groups.index');
    Route::get('/vatssa/members/{cid}', [VatssaGroupController::class, 'apiMember'])->whereNumber('cid')->name('api.vatssa.members.show');
});
