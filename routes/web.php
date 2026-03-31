<?php

use App\Http\Controllers\HemerotecaController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('hemeroteca', HemerotecaController::class)->name('hemeroteca');
    Route::post('hemeroteca/sources', [HemerotecaController::class, 'store'])->name('hemeroteca.sources.store');
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
