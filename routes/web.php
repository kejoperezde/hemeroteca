<?php

use App\Http\Controllers\HemerotecaController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('hemeroteca', HemerotecaController::class)->name('hemeroteca');
    Route::get('hemeroteca/register', [HemerotecaController::class, 'showRegisterForm'])->name('hemeroteca.register');
    Route::post('hemeroteca/api/sources/draft', [HemerotecaController::class, 'uploadDraftApi'])
        ->middleware('throttle:10,1')
        ->name('hemeroteca.sources.upload-draft-api');
    Route::post('hemeroteca/sources/draft/discard', [HemerotecaController::class, 'discardDraftApi'])
        ->name('hemeroteca.sources.draft.discard');
    Route::get('hemeroteca/sources/draft/{draftToken}/thumbnail', [HemerotecaController::class, 'thumbnailDraft'])
        ->name('hemeroteca.sources.draft.thumbnail');
    Route::post('hemeroteca/sources', [HemerotecaController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('hemeroteca.sources.store');
    Route::get('hemeroteca/sources/{sourceId}/replay/{asset}', [HemerotecaController::class, 'replayAsset'])
        ->whereNumber('sourceId')
        ->where('asset', '.*')
        ->name('hemeroteca.sources.replay.asset');
    Route::get('hemeroteca/sources/{sourceId}/backup', [HemerotecaController::class, 'openBackup'])
        ->whereNumber('sourceId')
        ->name('hemeroteca.sources.backup.open');
    Route::get('hemeroteca/sources/{sourceId}/backup/download', [HemerotecaController::class, 'downloadBackup'])
        ->whereNumber('sourceId')
        ->name('hemeroteca.sources.backup.download');
    Route::get('hemeroteca/sources/{sourceId}/backup/thumbnail', [HemerotecaController::class, 'thumbnailBackup'])
        ->whereNumber('sourceId')
        ->name('hemeroteca.sources.backup.thumbnail');
});

require __DIR__.'/settings.php';
