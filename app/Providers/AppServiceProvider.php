<?php

namespace App\Providers;

use App\Contracts\AiProviderContract;
use App\Models\WeeklyCallReport;
use App\Policies\WeeklyCallReportPolicy;
use App\Services\StubAiProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind AI provider contract to implementation
        // Uses StubAiProvider by default; configure AI_PROVIDER in .env for real provider
        $this->app->singleton(AiProviderContract::class, function () {
            $provider = config('services.ai.provider', 'stub');

            return match ($provider) {
                'openai' => $this->resolveOpenAiProvider(),
                'anthropic' => $this->resolveAnthropicProvider(),
                'openrouter' => $this->resolveOpenRouterProvider(),
                default => new StubAiProvider(),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register policies
        Gate::policy(WeeklyCallReport::class, WeeklyCallReportPolicy::class);
    }

    /**
     * Resolve OpenAI provider if configured.
     */
    private function resolveOpenAiProvider(): AiProviderContract
    {
        // Placeholder for OpenAI implementation
        // Would be implemented when OpenAI integration is available
        return new StubAiProvider();
    }

    /**
     * Resolve Anthropic provider if configured.
     */
    private function resolveAnthropicProvider(): AiProviderContract
    {
        // Placeholder for Anthropic implementation
        // Would be implemented when Anthropic integration is available
        return new StubAiProvider();
    }

    /**
     * Resolve OpenRouter provider if configured.
     */
    private function resolveOpenRouterProvider(): AiProviderContract
    {
        // Placeholder for OpenRouter implementation
        // Would be implemented when OpenRouter integration is available
        return new StubAiProvider();
    }
}
