<?php

use App\Http\Controllers\Api\PbxIngestionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/pbx')
    ->middleware(['api', 'pbx_auth'])
    ->group(function () {
        Route::post('/recordings/ingest', [PbxIngestionController::class, 'store']);
    });
