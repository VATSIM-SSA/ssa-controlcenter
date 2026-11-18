<?php

use App\Http\Controllers\VatssaDiscordSyncController;
use Illuminate\Support\Facades\Route;

/*
| VATSSA API routes. Loaded by VatssaServiceProvider under /api with the api
| middleware group and an API key (any key, read-only is enough).
*/

Route::get('/vatssa/discord-sync-requests', [VatssaDiscordSyncController::class, 'index'])->name('api.vatssa.discord-sync.index');
