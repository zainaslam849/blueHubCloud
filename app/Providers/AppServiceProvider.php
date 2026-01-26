<?php

namespace App\Providers;

use App\Models\WeeklyCallReport;
use App\Policies\WeeklyCallReportPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Intentionally empty.
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register policies
        Gate::policy(WeeklyCallReport::class, WeeklyCallReportPolicy::class);
    }
}
