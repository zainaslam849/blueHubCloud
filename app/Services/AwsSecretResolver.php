<?php

namespace App\Services;

use Aws\SecretsManager\SecretsManagerClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Small helper to resolve AWS Secrets Manager secrets using the default
 * AWS SDK provider chain (instance profile / role credentials on EC2).
 * Results are cached for a short TTL to avoid repeated network calls.
 */
class AwsSecretResolver
{
    protected int $ttlSeconds = 600; // cache for 10 minutes

    /**
     * Fetch a secret value. Returns the raw secret string, or null on failure.
     */
    public function getSecretString(string $secretName): ?string
    {
        $cacheKey = $this->cacheKey($secretName);

        return Cache::remember($cacheKey, $this->ttlSeconds, function () use ($secretName) {
            try {
                $region = Config::get('services.pbxware.aws_region') ?: env('AWS_DEFAULT_REGION');
                $client = new SecretsManagerClient([
                    'version' => 'latest',
                    'region' => $region,
                ]);

                $result = $client->getSecretValue(['SecretId' => $secretName]);
                $secretString = $result['SecretString'] ?? null;

                if ($secretString === null) {
                    Log::warning('AwsSecretResolver: secret returned no SecretString', ['secret' => $secretName]);
                }

                return $secretString;
            } catch (\Throwable $e) {
                Log::error('AwsSecretResolver: failed to fetch secret', ['secret' => $secretName, 'error' => $e->getMessage()]);
                return null;
            }
        });
    }

    /**
     * Fetch a secret and decode as JSON array. Returns array or null on failure.
     */
    public function getSecretJson(string $secretName): ?array
    {
        $raw = $this->getSecretString($secretName);
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('AwsSecretResolver: secret JSON decode failed', ['secret' => $secretName, 'error' => json_last_error_msg()]);
            return null;
        }

        return $decoded;
    }

    protected function cacheKey(string $secretName): string
    {
        return 'aws_secret_resolver:' . sha1($secretName);
    }
}
