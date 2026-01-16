<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\AdminCallsController;
use App\Http\Controllers\Admin\AdminTranscriptionsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Admin auth API (session-based)
Route::prefix('admin/api')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware(['admin'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/calls', [AdminCallsController::class, 'index']);
        Route::get('/calls/{idOrUid}', [AdminCallsController::class, 'show']);
        Route::get('/transcriptions', [AdminTranscriptionsController::class, 'index']);
        Route::get('/transcriptions/{id}', [AdminTranscriptionsController::class, 'show']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/pbx/ingest', [\App\Http\Controllers\Admin\PbxIngestController::class, 'trigger']);
    });
});

// Admin SPA entry
Route::view('/admin/login', 'admin')->middleware('admin.guest');

Route::view('/admin/dashboard', 'admin')->middleware('admin');

Route::view('/admin/{any?}', 'admin')
    ->where('any', '.*')
    ->middleware('admin');
