<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\AdminCallsController;
use App\Http\Controllers\Admin\AdminCompaniesController;
use App\Http\Controllers\Admin\AdminPbxAccountsController;
use App\Http\Controllers\Admin\AdminAiRegenerateController;
use App\Http\Controllers\Admin\AdminPlansController;
use App\Http\Controllers\Admin\AdminUsersController;
use App\Http\Controllers\Admin\AdminWeeklyCallReportsController;
use App\Http\Controllers\Admin\AdminTranscriptionsController;
use App\Http\Controllers\Admin\AdminTenantSyncController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\SubCategoryController;
use App\Http\Controllers\Admin\CallCategorizationController;
use App\Http\Controllers\Admin\CategoryOverrideController;
use App\Http\Controllers\Admin\AdminAiCategoryController;
use App\Http\Controllers\User\AuthController as UserAuthController;
use App\Http\Controllers\User\UserCallsController;
use App\Http\Controllers\User\UserDashboardController;
use App\Http\Controllers\User\UserJobsController;
use App\Http\Controllers\User\UserAvailablePlansController;
use App\Http\Controllers\User\UserPlanController;
use App\Http\Controllers\User\UserWeeklyCallReportsController;
use App\Http\Controllers\User\StripeController;
use App\Http\Controllers\User\PurchaseHistoryController;
use App\Http\Controllers\Admin\AdminPurchasesController;
use App\Http\Controllers\Admin\AdminSettingsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::get('/', function () {
    return redirect('/admin/login');
});

Route::get('/cron/pbx-sync', function (Request $request) {
    $configuredToken = (string) config('services.scheduler.token', '');

    // Prefer header-based auth (X-Scheduler-Token); accept query token for
    // backward compatibility with already-configured external schedulers.
    $providedToken = (string) ($request->header('X-Scheduler-Token') ?? '');
    if ($providedToken === '') {
        $providedToken = (string) $request->query('token', '');
    }

    $correlationId = (string) Str::uuid();

    if ($configuredToken === '' || $providedToken === '' || ! hash_equals($configuredToken, $providedToken)) {
        Log::warning('Cron webhook unauthorized', [
            'correlation_id' => $correlationId,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Unauthorized',
            'correlation_id' => $correlationId,
        ], 403);
    }

    try {
        Artisan::call('pbx:sync-tenants');
        // NOTE: Artisan output is intentionally NOT returned to the caller to
        // avoid leaking internal command output. Full output is logged.
        $output = trim(Artisan::output());

        Log::info('Cron webhook executed pbx:sync-tenants', [
            'correlation_id' => $correlationId,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'exit_code' => 0,
            'output_length' => strlen($output),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Tenant sync triggered.',
            'correlation_id' => $correlationId,
        ]);
    } catch (\Throwable $e) {
        Log::error('Cron webhook failed to execute pbx:sync-tenants', [
            'correlation_id' => $correlationId,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'error' => $e->getMessage(),
            'exception' => get_class($e),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Tenant sync failed to trigger.',
            'correlation_id' => $correlationId,
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
        Route::post('/calls/{idOrUid}/regenerate-ai', [AdminCallsController::class, 'regenerate']);
        Route::post('/calls/{idOrUid}/check-transcript', [AdminCallsController::class, 'checkTranscript']);
        Route::delete('/calls/{idOrUid}', [AdminCallsController::class, 'destroy']);
        Route::get('/transcriptions', [AdminTranscriptionsController::class, 'index']);
        Route::get('/transcriptions/{id}', [AdminTranscriptionsController::class, 'show']);
            Route::get('/ai-settings', [\App\Http\Controllers\Admin\AdminAiSettingsController::class, 'index']);
            Route::post('/ai-settings', [\App\Http\Controllers\Admin\AdminAiSettingsController::class, 'store']);
            Route::post('/ai-settings/test', [\App\Http\Controllers\Admin\AdminAiSettingsController::class, 'test']);
        Route::get('/weekly-call-reports', [AdminWeeklyCallReportsController::class, 'index']);
        Route::get('/weekly-call-reports/{id}', [AdminWeeklyCallReportsController::class, 'show'])
            ->whereNumber('id');
        Route::get('/ai/pending', [AdminAiRegenerateController::class, 'pendingStats']);
        Route::post('/ai/regenerate', [AdminAiRegenerateController::class, 'regenerate']);
        
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

        // SaaS plan management
        Route::get('/plans', [AdminPlansController::class, 'index']);
        Route::post('/plans', [AdminPlansController::class, 'store']);
        Route::get('/plans/{id}', [AdminPlansController::class, 'show']);
        Route::put('/plans/{id}', [AdminPlansController::class, 'update']);
        Route::delete('/plans/{id}', [AdminPlansController::class, 'destroy']);

        // SaaS user management
        Route::get('/users', [AdminUsersController::class, 'index']);
        Route::get('/users/unassigned', [AdminUsersController::class, 'unassigned']);
        Route::get('/users/{id}', [AdminUsersController::class, 'show'])->whereNumber('id');
        Route::patch('/users/{id}/toggle-status', [AdminUsersController::class, 'toggleStatus']);
        Route::delete('/users/{id}', [AdminUsersController::class, 'destroy']);

        // Company user/plan assignment
        Route::post('/companies/{id}/assign-user', [AdminCompaniesController::class, 'assignUser']);
        Route::post('/companies/{id}/assign-plan', [AdminCompaniesController::class, 'assignPlan']);

        // Purchase history (admin)
        Route::get('/purchases', [AdminPurchasesController::class, 'index']);

        Route::post('/pbx/ingest', [\App\Http\Controllers\Admin\PbxIngestController::class, 'trigger']);
        Route::post('/pipeline/run', [\App\Http\Controllers\Admin\AdminPipelineController::class, 'run']);
        Route::get('/system/status', [\App\Http\Controllers\Admin\AdminSystemStatusController::class, 'show']);
        Route::get('/jobs/overview', [\App\Http\Controllers\Admin\AdminJobsController::class, 'overview']);
        Route::post('/jobs/workers/start', [\App\Http\Controllers\Admin\AdminJobsController::class, 'startWorkers']);
        Route::post('/jobs/pipelines/{pipelineRunId}/resume', [\App\Http\Controllers\Admin\AdminJobsController::class, 'resumePipeline'])
            ->whereNumber('pipelineRunId');
        Route::get('/settings', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'show']);
        Route::post('/settings', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'update']);
        Route::post('/settings/password', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'updatePassword']);
        Route::post('/settings/smtp', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'updateSmtp']);
        Route::get('/settings/stripe', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'showStripe']);
        Route::post('/settings/stripe', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'updateStripe']);
    });
});

// Admin SPA entry
Route::view('/admin/login', 'admin')->middleware('admin.guest');

Route::view('/admin/dashboard', 'admin')->middleware('admin');

Route::view('/admin/{any?}', 'admin')
    ->where('any', '.*')
    ->middleware('admin');

// ─────────────────────────────────────────────
// User (SaaS) API  — session auth, web guard
// ─────────────────────────────────────────────
Route::prefix('api/v1')->group(function () {
    // Public branding – logo/name for the user SPA
    Route::get('/settings/branding', function () {
        $settings = \App\Models\AppSetting::query()->first();
        $rawLogo = $settings?->admin_logo_url;
        $logoUrl = null;
        if ($rawLogo) {
            if (str_starts_with($rawLogo, 'http')) {
                $logoUrl = $rawLogo;
            } else {
                // Strip any leading slash so asset() doesn't produce a double-slash URL
                $logoUrl = asset(ltrim($rawLogo, '/'));
            }
        }
        return response()->json([
            'data' => [
                'site_name' => $settings?->site_name ?? config('app.name'),
                'logo_url'  => $logoUrl,
            ],
        ]);
    });

    Route::post('/auth/register', [UserAuthController::class, 'register'])
        ->middleware(['user.guest', 'throttle:10,1']);

    Route::post('/auth/verify-email', [UserAuthController::class, 'verifyEmail'])
        ->middleware('throttle:10,1');

    Route::post('/auth/resend-verification', [UserAuthController::class, 'resendVerification'])
        ->middleware('throttle:5,1');

    Route::post('/auth/login', [UserAuthController::class, 'login'])
        ->middleware('throttle:10,1');

    Route::middleware('user')->group(function () {
        Route::get('/auth/me',       [UserAuthController::class, 'me']);
        Route::post('/auth/logout',  [UserAuthController::class, 'logout']);

        Route::get('/dashboard',     [UserDashboardController::class, 'show']);
        Route::get('/reports',       [UserWeeklyCallReportsController::class, 'index']);
        Route::get('/reports/{id}',  [UserWeeklyCallReportsController::class, 'show'])->whereNumber('id');
        Route::get('/calls',         [UserCallsController::class, 'index']);
        Route::get('/jobs/overview', [UserJobsController::class, 'overview']);
        Route::get('/plan',                [UserPlanController::class, 'show']);
        Route::get('/plans/available',     [UserAvailablePlansController::class, 'index']);

        // Stripe checkout
        Route::post('/stripe/create-checkout', [StripeController::class, 'createCheckout']);
        Route::get('/stripe/verify/{sessionId}', [StripeController::class, 'verify']);

        // Purchase history
        Route::get('/purchases', [PurchaseHistoryController::class, 'index']);
    });
});

// Stripe webhook — CSRF excluded via bootstrap/app.php
Route::post('/stripe/webhook', [StripeController::class, 'webhook']);

// User SPA entry — serves dashboard/src Vue app
Route::view('/select-plan',     'app')->middleware('user');
Route::view('/login',           'app')->middleware('user.guest');
Route::view('/register',        'app')->middleware('user.guest');
Route::view('/dashboard',       'app')->middleware('user');
Route::view('/reports',         'app')->middleware('user');
Route::view('/reports/{any}',   'app')->middleware('user')->where('any', '.*');
Route::view('/usage',           'app')->middleware('user');
Route::view('/account',         'app')->middleware('user');
Route::view('/companies',       'app')->middleware('user');
Route::view('/pbx-accounts',    'app')->middleware('user');
Route::view('/plans',           'app')->middleware('user');
Route::view('/admin-users',     'app')->middleware('user');
Route::view('/ai-processing',   'app')->middleware('user');
Route::view('/billing',         'app')->middleware('user');
Route::view('/purchase/success','app')->middleware('user');
Route::view('/admin/purchases', 'app')->middleware('user');
