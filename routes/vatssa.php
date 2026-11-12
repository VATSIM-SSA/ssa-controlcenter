<?php

use App\Http\Controllers\VatssaStalledController;
use App\Http\Controllers\VatssaTheoryController;
use Illuminate\Support\Facades\Route;

/*
| VATSSA routes. Loaded by VatssaServiceProvider with the same middleware as
| the authenticated group in routes/web.php: web, auth, activity, suspended.
*/

Route::post('/training/{training}/action/theory', [VatssaTheoryController::class, 'update'])->name('training.action.theory');
Route::post('/training/{training}/action/stalled', [VatssaStalledController::class, 'update'])->name('training.action.stalled');
