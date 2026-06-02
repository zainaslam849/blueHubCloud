<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminSettingsUpdateRequest;
use App\Http\Requests\Admin\UpdatePasswordRequest;
use App\Models\AppSetting;
use App\Services\AwsSecretsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AdminSettingsController extends Controller
{
    /**
     * In-request cache for settings S3 config resolved via Secrets Manager.
     *
     * @var array<string, mixed>|null
     */
    private ?array $settingsS3ConfigCache = null;

    public function show(): JsonResponse
    {
        $settings = AppSetting::query()->first();
        $adminLogoUrl      = $this->sanitizeAssetUrl($settings?->admin_logo_url);
        $adminLogoLightUrl = $this->sanitizeAssetUrl($settings?->admin_logo_light_url);
        $adminLogoDarkUrl  = $this->sanitizeAssetUrl($settings?->admin_logo_dark_url);
        $adminFaviconUrl   = $this->sanitizeAssetUrl($settings?->admin_favicon_url);

        return response()->json([
            'data' => [
                'site_name'                 => $settings?->site_name,
                'admin_logo_url'            => $adminLogoUrl,
                'admin_logo_light_url'      => $adminLogoLightUrl,
                'admin_logo_dark_url'       => $adminLogoDarkUrl,
                'admin_favicon_url'         => $adminFaviconUrl,
                'smtp_host'                 => $settings?->smtp_host,
                'smtp_port'                 => $settings?->smtp_port,
                'smtp_encryption'           => $settings?->smtp_encryption ?? 'tls',
                'smtp_username'             => $settings?->smtp_username,
                'smtp_from_address'         => $settings?->smtp_from_address,
                'smtp_from_name'            => $settings?->smtp_from_name,
                'admin_notification_email'  => $settings?->admin_notification_email,
                // password is not returned for security
            ],
        ]);
    }

    public function updateSmtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'smtp_host'                => ['nullable', 'string', 'max:255'],
            'smtp_port'                => ['nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_encryption'          => ['nullable', 'string', 'in:tls,ssl,starttls,'],
            'smtp_username'            => ['nullable', 'string', 'max:255'],
            'smtp_password'            => ['nullable', 'string', 'max:255'],
            'smtp_from_address'        => ['nullable', 'email', 'max:255'],
            'smtp_from_name'           => ['nullable', 'string', 'max:255'],
            'admin_notification_email' => ['nullable', 'email', 'max:255'],
        ]);

        $settings = AppSetting::query()->first() ?? new AppSetting();

        foreach (['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_from_address', 'smtp_from_name', 'admin_notification_email'] as $field) {
            if (array_key_exists($field, $validated)) {
                $settings->$field = $validated[$field] ?: null;
            }
        }

        // Only overwrite password if a new one was submitted
        if (! empty($validated['smtp_password'])) {
            $settings->smtp_password = $validated['smtp_password'];
        }

        $settings->save();

        return response()->json([
            'message' => 'Email settings saved.',
            'data' => [
                'smtp_host'                => $settings->smtp_host,
                'smtp_port'                => $settings->smtp_port,
                'smtp_encryption'          => $settings->smtp_encryption,
                'smtp_username'            => $settings->smtp_username,
                'smtp_from_address'        => $settings->smtp_from_address,
                'smtp_from_name'           => $settings->smtp_from_name,
                'admin_notification_email' => $settings->admin_notification_email,
            ],
        ]);
    }

    public function updateStripe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'stripe_public_key'     => ['nullable', 'string', 'max:255'],
            'stripe_secret_key'     => ['nullable', 'string', 'max:500'],
            'stripe_webhook_secret' => ['nullable', 'string', 'max:500'],
            'stripe_test_mode'      => ['sometimes', 'boolean'],
        ]);

        $settings = AppSetting::query()->first() ?? new AppSetting();

        if (array_key_exists('stripe_public_key', $validated)) {
            $settings->stripe_public_key = $validated['stripe_public_key'] ?: null;
        }
        if (! empty($validated['stripe_secret_key'])) {
            $settings->stripe_secret_key = $validated['stripe_secret_key'];
        }
        if (! empty($validated['stripe_webhook_secret'])) {
            $settings->stripe_webhook_secret = $validated['stripe_webhook_secret'];
        }
        if (array_key_exists('stripe_test_mode', $validated)) {
            $settings->stripe_test_mode = (bool) $validated['stripe_test_mode'];
        }

        $settings->save();

        return response()->json(['message' => 'Stripe settings saved.']);
    }

    public function showStripe(): JsonResponse
    {
        $settings = AppSetting::query()->first();
        return response()->json([
            'data' => [
                'stripe_public_key' => $settings?->stripe_public_key,
                'stripe_test_mode'  => $settings?->stripe_test_mode ?? true,
                // secret keys are never returned
            ],
        ]);
    }

    private function sanitizeAssetUrl(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $trimmed = trim($url);
        $path = parse_url($trimmed, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return $trimmed;
        }

        $normalizedPath = ltrim($path, '/');

        if (str_starts_with($normalizedPath, 'storage/')) {
            $relativePublicPath = ltrim(substr($normalizedPath, strlen('storage/')), '/');
            if ($relativePublicPath === '' || ! Storage::disk('public')->exists($relativePublicPath)) {
                return null;
            }
        }

        return $trimmed;
    }

    public function update(AdminSettingsUpdateRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $settings = AppSetting::query()->first();
        if (! $settings) {
            $settings = new AppSetting();
        }

        if (array_key_exists('site_name', $validated)) {
            $settings->site_name = $validated['site_name'];
        }

        if ($request->boolean('admin_logo_clear')) {
            $this->deleteStoredFile($settings->admin_logo_url);
            $settings->admin_logo_url = null;
        }
        if ($request->boolean('admin_logo_light_clear')) {
            $this->deleteStoredFile($settings->admin_logo_light_url);
            $settings->admin_logo_light_url = null;
        }
        if ($request->boolean('admin_logo_dark_clear')) {
            $this->deleteStoredFile($settings->admin_logo_dark_url);
            $settings->admin_logo_dark_url = null;
        }

        if ($request->boolean('admin_favicon_clear')) {
            $this->deleteStoredFile($settings->admin_favicon_url);
            $settings->admin_favicon_url = null;
        }

        if ($request->hasFile('admin_logo')) {
            $this->deleteStoredFile($settings->admin_logo_url);
            $settings->admin_logo_url = $this->storeAssetAndGetUrl($request, 'admin_logo');
        }
        if ($request->hasFile('admin_logo_light')) {
            $this->deleteStoredFile($settings->admin_logo_light_url);
            $settings->admin_logo_light_url = $this->storeAssetAndGetUrl($request, 'admin_logo_light');
        }
        if ($request->hasFile('admin_logo_dark')) {
            $this->deleteStoredFile($settings->admin_logo_dark_url);
            $settings->admin_logo_dark_url = $this->storeAssetAndGetUrl($request, 'admin_logo_dark');
        }

        if ($request->hasFile('admin_favicon')) {
            $this->deleteStoredFile($settings->admin_favicon_url);
            $settings->admin_favicon_url = $this->storeAssetAndGetUrl($request, 'admin_favicon');
        }

        $settings->save();

        return response()->json([
            'data' => [
                'site_name'            => $settings->site_name,
                'admin_logo_url'       => $this->sanitizeAssetUrl($settings->admin_logo_url),
                'admin_logo_light_url' => $this->sanitizeAssetUrl($settings->admin_logo_light_url),
                'admin_logo_dark_url'  => $this->sanitizeAssetUrl($settings->admin_logo_dark_url),
                'admin_favicon_url'    => $this->sanitizeAssetUrl($settings->admin_favicon_url),
            ],
        ]);
    }

    private function settingsAssetDisk(): string
    {
        $configured = (string) config('filesystems.app_settings_disk', 'public');
        $configured = trim($configured) !== '' ? trim($configured) : 'public';

        $disks = config('filesystems.disks', []);
        if (! is_array($disks) || ! array_key_exists($configured, $disks)) {
            return 'public';
        }

        try {
            // Force adapter resolution so missing Flysystem drivers are caught here,
            // then gracefully fallback to local public disk for settings uploads.
            Storage::disk($configured)->exists('__settings_disk_probe__');
            return $configured;
        } catch (\Throwable $e) {
            return 'public';
        }
    }

    /**
     * Build an on-demand storage adapter for settings uploads.
     *
     * When APP_SETTINGS_DISK=s3, credentials are resolved from AWS Secrets
     * Manager first (project standard), then fallback to existing disk config.
     */
    private function settingsAssetStorage()
    {
        $disk = $this->settingsAssetDisk();

        if ($disk !== 's3') {
            return Storage::disk($disk);
        }

        $secretConfig = $this->resolveSettingsS3ConfigFromSecrets();
        if (is_array($secretConfig)) {
            return Storage::build($secretConfig);
        }

        return Storage::disk('s3');
    }

    /**
     * Resolve S3 configuration for admin settings assets from Secrets Manager.
     *
     * Expected secret payload supports either AWS-style keys
     * (AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_DEFAULT_REGION, AWS_BUCKET)
     * or compact aliases (key, secret, region, bucket).
     */
    private function resolveSettingsS3ConfigFromSecrets(): ?array
    {
        if ($this->settingsS3ConfigCache !== null) {
            return $this->settingsS3ConfigCache;
        }

        $secretName = (string) config('filesystems.app_settings_secret_name', 'app/settings-storage');
        $secretName = trim($secretName);

        if ($secretName === '') {
            $this->settingsS3ConfigCache = null;
            return null;
        }

        try {
            /** @var AwsSecretsService $secrets */
            $secrets = app(AwsSecretsService::class);
            $secret = $secrets->get($secretName);
        } catch (\Throwable $e) {
            Log::warning('AdminSettingsController: failed to resolve settings S3 secret; falling back to configured s3 disk', [
                'secret' => $secretName,
                'error' => $e->getMessage(),
            ]);
            $this->settingsS3ConfigCache = null;
            return null;
        }

        $key = $this->firstNonEmptySecretValue($secret, [
            'AWS_ACCESS_KEY_ID', 'aws_access_key_id', 'access_key_id', 'key',
        ]);
        $secretKey = $this->firstNonEmptySecretValue($secret, [
            'AWS_SECRET_ACCESS_KEY', 'aws_secret_access_key', 'secret_access_key', 'secret',
        ]);
        $region = $this->firstNonEmptySecretValue($secret, [
            'AWS_DEFAULT_REGION', 'aws_default_region', 'aws_region', 'region',
        ]);
        $bucket = $this->firstNonEmptySecretValue($secret, [
            'AWS_BUCKET', 'aws_bucket', 'bucket',
        ]);

        if (! $key || ! $secretKey || ! $region || ! $bucket) {
            Log::warning('AdminSettingsController: settings S3 secret is incomplete; falling back to configured s3 disk', [
                'secret' => $secretName,
                'has_key' => (bool) $key,
                'has_secret' => (bool) $secretKey,
                'has_region' => (bool) $region,
                'has_bucket' => (bool) $bucket,
            ]);
            $this->settingsS3ConfigCache = null;
            return null;
        }

        $config = [
            'driver' => 's3',
            'key' => $key,
            'secret' => $secretKey,
            'region' => $region,
            'bucket' => $bucket,
            'url' => $this->firstNonEmptySecretValue($secret, ['AWS_URL', 'aws_url', 'url']) ?: config('services.aws.url'),
            'endpoint' => $this->firstNonEmptySecretValue($secret, ['AWS_ENDPOINT', 'aws_endpoint', 'endpoint']) ?: config('services.aws.endpoint'),
            'use_path_style_endpoint' => filter_var(
                $this->firstNonEmptySecretValue($secret, ['AWS_USE_PATH_STYLE_ENDPOINT', 'aws_use_path_style_endpoint', 'use_path_style_endpoint'])
                    ?? config('services.aws.use_path_style_endpoint', false),
                FILTER_VALIDATE_BOOLEAN
            ),
            'visibility' => 'public',
            'throw' => false,
        ];

        $this->settingsS3ConfigCache = $config;
        return $this->settingsS3ConfigCache;
    }

    /**
     * Return the first non-empty scalar value from secret array by candidate keys.
     *
     * @param  array<string, mixed>  $secret
     * @param  array<int, string>  $keys
     */
    private function firstNonEmptySecretValue(array $secret, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (! array_key_exists($k, $secret)) {
                continue;
            }
            $v = $secret[$k];
            if (! is_scalar($v)) {
                continue;
            }
            $s = trim((string) $v);
            if ($s !== '') {
                return $s;
            }
        }

        return null;
    }

    private function storeAssetAndGetUrl(Request $request, string $field): string
    {
        $storage = $this->settingsAssetStorage();
        $path = $storage->putFile('app-settings', $request->file($field), 'public');
        return $storage->url($path);
    }

    private function deleteStoredFile(?string $url): void
    {
        if (! $url) {
            return;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return;
        }

        $relative = ltrim($path, '/');
        $relativePublic = str_starts_with($relative, 'storage/')
            ? ltrim(substr($relative, strlen('storage/')), '/')
            : $relative;

        // Public disk path format: /storage/app-settings/... -> app-settings/...
        if ($relativePublic !== '') {
            try {
                Storage::disk('public')->delete($relativePublic);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // Configured disk path can be either app-settings/... or include storage/ prefix.
        $relativeDisk = str_starts_with($relative, 'storage/')
            ? ltrim(substr($relative, strlen('storage/')), '/')
            : $relative;

        $secretS3Config = $this->resolveSettingsS3ConfigFromSecrets();
        if (is_array($secretS3Config) && isset($secretS3Config['bucket'])) {
            $bucketPrefix = trim((string) $secretS3Config['bucket'], '/') . '/';
            if (str_starts_with($relativeDisk, $bucketPrefix)) {
                $relativeDisk = substr($relativeDisk, strlen($bucketPrefix));
            }
        }

        if ($relativeDisk !== '') {
            try {
                $this->settingsAssetStorage()->delete($relativeDisk);
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = Auth::guard('admin')->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $user->password = Hash::make($validated['new_password']);
        $user->save();

        return response()->json(['message' => 'Password updated.']);
    }
}
