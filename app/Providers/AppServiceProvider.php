<?php

namespace App\Providers;

use App\Contracts\TranscriptionService;
use App\Services\OpenAIWhisperTranscriptionService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TranscriptionService::class, OpenAIWhisperTranscriptionService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
