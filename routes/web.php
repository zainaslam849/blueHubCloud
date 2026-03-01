<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\AdminCallsController;
use App\Http\Controllers\Admin\AdminCompaniesController;
use App\Http\Controllers\Admin\AdminPbxAccountsController;
use App\Http\Controllers\Admin\AdminWeeklyCallReportsController;
use App\Http\Controllers\Admin\AdminTranscriptionsController;
use App\Http\Controllers\Admin\AdminTenantSyncController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\SubCategoryController;
use App\Http\Controllers\Admin\CallCategorizationController;
use App\Http\Controllers\Admin\CategoryOverrideController;
use App\Http\Controllers\Admin\AdminAiCategoryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin/login');
});

Route::get('/cron/pbx-sync', function (Request $request) {
    $configuredToken = (string) config('services.scheduler.token', '');
    $providedToken = (string) $request->query('token', '');

    if ($configuredToken === '' || $providedToken === '' || !hash_equals($configuredToken, $providedToken)) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized',
        ], 403);
    }

    try {
        Artisan::call('pbx:sync-tenants');
        $output = trim(Artisan::output());

        Log::info('Cron webhook executed pbx:sync-tenants', [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'exit_code' => 0,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Tenant sync triggered.',
            'output' => $output,
        ]);
    } catch (\Throwable $e) {
        Log::error('Cron webhook failed to execute pbx:sync-tenants', [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Tenant sync failed to trigger.',
            'error' => $e->getMessage(),
        ], 500);
    }
})->name('cron.pbx-sync')->middleware('throttle:20,1');

// Admin auth API (session-based)
Route::prefix('admin/api')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware(['admin'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        
        // Companies management
        Route::get('/companies/dropdown', [AdminCompaniesController::class, 'dropdown']);
        Route::get('/companies', [AdminCompaniesController::class, 'index']);
        Route::post('/companies', [AdminCompaniesController::class, 'store']);
        Route::put('/companies/{id}', [AdminCompaniesController::class, 'update']);
        Route::delete('/companies/{id}', [AdminCompaniesController::class, 'destroy']);
        Route::delete('/companies/{id}/force-delete', [AdminCompaniesController::class, 'forceDelete']);
        Route::post('/companies/sync-tenants', [AdminCompaniesController::class, 'syncTenants']);
        Route::get('/companies/available-tenants', [AdminCompaniesController::class, 'availableTenants']);
        
        // Tenant sync settings
        Route::get('/tenant-sync-settings', [AdminTenantSyncController::class, 'index']);
        Route::get('/tenant-sync-settings/{providerId}', [AdminTenantSyncController::class, 'show']);
        Route::put('/tenant-sync-settings/{providerId}', [AdminTenantSyncController::class, 'update']);
        Route::post('/tenant-sync-settings/{providerId}/trigger', [AdminTenantSyncController::class, 'triggerSync']);
        
        // PBX Accounts management
        Route::get('/pbx-accounts', [AdminPbxAccountsController::class, 'index']);
        Route::post('/pbx-accounts', [AdminPbxAccountsController::class, 'store']);
        Route::get('/pbx-accounts/{id}', [AdminPbxAccountsController::class, 'show']);
        Route::put('/pbx-accounts/{id}', [AdminPbxAccountsController::class, 'update']);
        Route::delete('/pbx-accounts/{id}', [AdminPbxAccountsController::class, 'destroy']);
        Route::get('/pbx-providers', [AdminPbxAccountsController::class, 'providers']);
        
        Route::get('/calls', [AdminCallsController::class, 'index']);
        Route::get('/calls/{idOrUid}', [AdminCallsController::class, 'show']);
        Route::get('/transcriptions', [AdminTranscriptionsController::class, 'index']);
        Route::get('/transcriptions/{id}', [AdminTranscriptionsController::class, 'show']);
            Route::get('/ai-settings', [\App\Http\Controllers\Admin\AdminAiSettingsController::class, 'index']);
            Route::post('/ai-settings', [\App\Http\Controllers\Admin\AdminAiSettingsController::class, 'store']);
        Route::get('/weekly-call-reports', [AdminWeeklyCallReportsController::class, 'index']);
        Route::get('/weekly-call-reports/{id}', [AdminWeeklyCallReportsController::class, 'show'])
            ->whereNumber('id');
        
        // Category routes
        Route::get('/categories', [CategoryController::class, 'index']);
        Route::get('/categories/enabled', [CategoryController::class, 'enabled']);
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::get('/categories/{category}', [CategoryController::class, 'show']);
        Route::put('/categories/{category}', [CategoryController::class, 'update']);
        Route::patch('/categories/{category}/toggle', [CategoryController::class, 'toggle']);
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);
        Route::post('/categories/{id}/restore', [CategoryController::class, 'restore']);
        Route::delete('/categories/{id}/force-delete', [CategoryController::class, 'forceDelete']);
        Route::get('/categories/ai-generate/preview', [AdminAiCategoryController::class, 'preview']);
        Route::post('/categories/ai-generate', [AdminAiCategoryController::class, 'generate']);

        // Sub-category routes
        Route::get('/categories/{categoryId}/sub-categories', [SubCategoryController::class, 'index']);
        Route::post('/categories/{categoryId}/sub-categories', [SubCategoryController::class, 'store']);
        Route::put('/categories/{categoryId}/sub-categories/{subCategoryId}', [SubCategoryController::class, 'update']);
        Route::patch('/categories/{categoryId}/sub-categories/{subCategoryId}/toggle', [SubCategoryController::class, 'toggle']);
        Route::delete('/categories/{categoryId}/sub-categories/{subCategoryId}', [SubCategoryController::class, 'destroy']);
        Route::post('/categories/{categoryId}/sub-categories/{subCategoryId}/restore', [SubCategoryController::class, 'restore']);
        Route::delete('/categories/{categoryId}/sub-categories/{subCategoryId}/force-delete', [SubCategoryController::class, 'forceDelete']);

        // AI Categorization routes
        Route::get('/categorization/enabled-categories', [CallCategorizationController::class, 'getEnabledCategories']);
        Route::get('/categorization/prompt', [CallCategorizationController::class, 'generatePrompt']);
        Route::post('/categorization/build-prompt', [CallCategorizationController::class, 'buildCallPrompt']);
        Route::post('/categorization/validate', [CallCategorizationController::class, 'validateCategorization']);
        Route::post('/categorization/persist', [CallCategorizationController::class, 'persistCategorization']);
        Route::post('/categorization/bulk-persist', [CallCategorizationController::class, 'bulkPersistCategorizations']);
        
        // Category override & confidence enforcement routes (STEP 5)
        Route::get('/categories/review/stats', [CategoryOverrideController::class, 'getConfidenceStats']);
        Route::get('/categories/review/calls-needing-review', [CategoryOverrideController::class, 'getCallsNeedingReview']);
        Route::post('/categories/override/single', [CategoryOverrideController::class, 'overrideCallCategory']);
        Route::post('/categories/override/bulk', [CategoryOverrideController::class, 'bulkOverride']);
        Route::post('/categories/enforce-threshold', [CategoryOverrideController::class, 'enforceThreshold']);
        
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/pbx/ingest', [\App\Http\Controllers\Admin\PbxIngestController::class, 'trigger']);
        Route::post('/pipeline/run', [\App\Http\Controllers\Admin\AdminPipelineController::class, 'run']);
        Route::get('/system/status', [\App\Http\Controllers\Admin\AdminSystemStatusController::class, 'show']);
        Route::get('/jobs/overview', [\App\Http\Controllers\Admin\AdminJobsController::class, 'overview']);
        Route::get('/settings', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'show']);
        Route::post('/settings', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'update']);
        Route::post('/settings/password', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'updatePassword']);
    });
});

// Admin SPA entry
Route::view('/admin/login', 'admin')->middleware('admin.guest');

Route::view('/admin/dashboard', 'admin')->middleware('admin');

Route::view('/admin/{any?}', 'admin')
    ->where('any', '.*')
    ->middleware('admin');
