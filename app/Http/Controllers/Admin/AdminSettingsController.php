<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
        $adminLogoUrl = $this->sanitizeAssetUrl($settings?->admin_logo_url);
        $adminFaviconUrl = $this->sanitizeAssetUrl($settings?->admin_favicon_url);

        return response()->json([
            'data' => [
                'site_name' => $settings?->site_name,
                'admin_logo_url' => $adminLogoUrl,
                'admin_favicon_url' => $adminFaviconUrl,
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

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_name' => ['nullable', 'string', 'max:120'],
            'admin_logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
            'admin_favicon' => ['nullable', 'file', 'mimes:png,ico,svg', 'max:1024'],
            'admin_logo_clear' => ['nullable', 'boolean'],
            'admin_favicon_clear' => ['nullable', 'boolean'],
        ]);

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

        if ($request->boolean('admin_favicon_clear')) {
            $this->deleteStoredFile($settings->admin_favicon_url);
            $settings->admin_favicon_url = null;
        }

        if ($request->hasFile('admin_logo')) {
            $this->deleteStoredFile($settings->admin_logo_url);
            $settings->admin_logo_url = $this->storeAssetAndGetUrl($request, 'admin_logo');
        }

        if ($request->hasFile('admin_favicon')) {
            $this->deleteStoredFile($settings->admin_favicon_url);
            $settings->admin_favicon_url = $this->storeAssetAndGetUrl($request, 'admin_favicon');
        }

        $settings->save();

        $adminLogoUrl = $this->sanitizeAssetUrl($settings->admin_logo_url);
        $adminFaviconUrl = $this->sanitizeAssetUrl($settings->admin_favicon_url);

        return response()->json([
            'data' => [
                'site_name' => $settings->site_name,
                'admin_logo_url' => $adminLogoUrl,
                'admin_favicon_url' => $adminFaviconUrl,
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
            'url' => $this->firstNonEmptySecretValue($secret, ['AWS_URL', 'aws_url', 'url']) ?: env('AWS_URL'),
            'endpoint' => $this->firstNonEmptySecretValue($secret, ['AWS_ENDPOINT', 'aws_endpoint', 'endpoint']) ?: env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => filter_var(
                $this->firstNonEmptySecretValue($secret, ['AWS_USE_PATH_STYLE_ENDPOINT', 'aws_use_path_style_endpoint', 'use_path_style_endpoint'])
                    ?? env('AWS_USE_PATH_STYLE_ENDPOINT', false),
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

    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

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
