<?php

use App\Http\Controllers\Api\PbxIngestionController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TranscriptionCallbackController;

Route::prefix('v1/pbx')
    ->middleware(['api', 'pbx_auth'])
    ->group(function () {
        Route::post('/recordings/ingest', [PbxIngestionController::class, 'store']);
    });

    // Transcription provider callback (Lambda)
    Route::post('/transcription/callback', [TranscriptionCallbackController::class, '__invoke']);
