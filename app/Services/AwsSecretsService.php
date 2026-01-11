<?php

namespace App\Services;

use Aws\SecretsManager\SecretsManagerClient;
use Aws\Exception\AwsException;
use Illuminate\Support\Facades\Log;

/**
 * Service to fetch JSON secrets from AWS Secrets Manager using the
 * default SDK credential provider (instance profile / environment chain).
 *
 * Caches values in-memory for the request lifecycle.
 */
class AwsSecretsService
{
    private SecretsManagerClient $client;

    /**
     * In-memory cache for secrets fetched during this request.
     * @var array<string,array>
     */
    private array $cache = [];

    public function __construct()
    {
        $region = env('AWS_DEFAULT_REGION') ?: config('filesystems.disks.s3.region') ?: 'us-east-1';

        $clientConfig = [
            'version' => 'latest',
            'region' => $region,
        ];

        // If static AWS credentials are provided in environment, prefer them
        // as the explicit credential source and log that fact. Otherwise
        // allow the SDK to use its default provider chain (shared config,
        // environment, instance profile / IAM role).
        $awsKey = env('AWS_ACCESS_KEY_ID');
        $awsSecret = env('AWS_SECRET_ACCESS_KEY');
        $awsToken = env('AWS_SESSION_TOKEN');

        if (! empty($awsKey) && ! empty($awsSecret)) {
            $clientConfig['credentials'] = [
                'key' => $awsKey,
                'secret' => $awsSecret,
            ];
            if (! empty($awsToken)) {
                $clientConfig['credentials']['token'] = $awsToken;
            }
            Log::info('AwsSecretsService: using AWS credentials from environment variables');
        } else {
            Log::info('AwsSecretsService: no AWS env credentials found; using SDK default provider (IAM role / instance profile / shared config)');
        }

        $this->client = new SecretsManagerClient($clientConfig);
    }

    /**
     * Retrieve a secret from AWS Secrets Manager and return decoded JSON as array.
     * Caches the result in-memory for the request lifecycle.
     *
     * @param string $secretName
     * @return array<string,mixed>
     * @throws \RuntimeException if secret cannot be retrieved or is not valid JSON
     */
    public function get(string $secretName): array
    {
        if (isset($this->cache[$secretName])) {
            return $this->cache[$secretName];
        }

        try {
            $result = $this->client->getSecretValue(['SecretId' => $secretName]);
        } catch (AwsException $e) {
            throw new \RuntimeException("Failed to retrieve secret '{$secretName}': " . $e->getMessage(), 0, $e);
        }

        $secretString = null;
        if (isset($result['SecretString'])) {
            $secretString = $result['SecretString'];
        } elseif (isset($result['SecretBinary'])) {
            $secretString = base64_decode($result['SecretBinary']);
        }

        if ($secretString === null) {
            throw new \RuntimeException("Secret '{$secretName}' has no SecretString or SecretBinary value.");
        }

        $decoded = json_decode($secretString, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException("Secret '{$secretName}' is not valid JSON or did not decode to an associative array.");
        }

        // Cache and return
        $this->cache[$secretName] = $decoded;
        return $decoded;
    }
}
