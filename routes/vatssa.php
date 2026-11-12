<?php

use App\Http\Controllers\VatssaTheoryController;
use App\Http\Controllers\VatssaWebsiteController;
use App\Http\Controllers\VatssaWebsiteFilesController;
use Illuminate\Support\Facades\Route;

/*
| VATSSA routes. Loaded by VatssaServiceProvider with the same middleware as
| the authenticated group in routes/web.php: web, auth, activity, suspended.
*/

Route::post('/training/{training}/action/theory', [VatssaTheoryController::class, 'update'])->name('training.action.theory');

// Website content (homepage data, policies, minutes). Each action checks its
// own global permission; see VatssaWebsiteController.
Route::prefix('website')->name('vatssa.website.')->controller(VatssaWebsiteController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/screenshot', 'screenshot')->name('screenshot');
    Route::post('/screenshot', 'saveScreenshot')->name('screenshot.save');
    Route::get('/staff', 'staff')->name('staff');
    Route::post('/staff', 'saveStaff')->name('staff.save');
    Route::get('/rvas', 'rvas')->name('rvas');
    Route::post('/rvas', 'saveRvas')->name('rvas.save');

    foreach (['policies', 'minutes'] as $page) {
        Route::get("/{$page}", 'documents')->defaults('page', $page)->name($page);
        Route::post("/{$page}", 'saveDocuments')->defaults('page', $page)->name("{$page}.save");
    }
});

// Plain file areas and the private archive.
Route::prefix('website')->name('vatssa.website.')->controller(VatssaWebsiteFilesController::class)->group(function () {
    foreach (['documents', 'staff-files'] as $area) {
        Route::get("/{$area}", 'index')->defaults('area', $area)->name($area);
        Route::post("/{$area}/upload", 'upload')->defaults('area', $area)->name("{$area}.upload");
        Route::post("/{$area}/archive", 'archive')->defaults('area', $area)->name("{$area}.archive");
        Route::get("/{$area}/download", 'download')->defaults('area', $area)->name("{$area}.download");
    }

    Route::get('/archive', 'archiveIndex')->name('archive');
    Route::get('/archive/download', 'archiveDownload')->name('archive.download');
    Route::post('/archive/delete', 'archiveDelete')->name('archive.delete');
});
