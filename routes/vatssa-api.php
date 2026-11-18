<?php

use App\Http\Controllers\VatssaDiscordSyncController;
use App\Http\Middleware\VatssaDiscordBotToken;
use Illuminate\Support\Facades\Route;

/*
| VATSSA API routes. Loaded by VatssaServiceProvider under /api with the api
| middleware group. Each route names its own authentication.
*/

// Read: any CC API key (read-only is enough).
Route::get('/vatssa/discord-sync-requests', [VatssaDiscordSyncController::class, 'index'])
    ->middleware('api-token')->name('api.vatssa.discord-sync.index');

// Write a result: only the bot's own token, never a CC API key.
Route::post('/vatssa/discord-sync-requests/{id}/result', [VatssaDiscordSyncController::class, 'result'])
    ->middleware(VatssaDiscordBotToken::class)->whereNumber('id')->name('api.vatssa.discord-sync.result');
