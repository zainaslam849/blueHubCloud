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
     * Enable verbose (but redacted) secrets logging.
     */
    private bool $debug;

    /**
     * In-memory cache for secrets fetched during this request.
     * @var array<string,array>
     */
    private array $cache = [];

    public function __construct()
    {
        $this->debug = filter_var(env('AWS_SECRETS_DEBUG', false), FILTER_VALIDATE_BOOLEAN);

        // Prefer a PBX-specific region override, then AWS default.
        // Default to ap-southeast-2 for this project.
        $region = env('PBXWARE_AWS_REGION')
            ?: (config('services.pbxware.aws_region') ?: null)
            ?: (env('AWS_DEFAULT_REGION') ?: null)
            ?: 'ap-southeast-2';

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

        Log::info('AwsSecretsService: Secrets Manager client initialized', [
            'region' => $region,
            'debug' => $this->debug,
        ]);
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

        if ($this->debug) {
            Log::info('AwsSecretsService: fetching secret', ['secret' => $secretName]);
        }

        try {
            $result = $this->client->getSecretValue(['SecretId' => $secretName]);
        } catch (AwsException $e) {
            Log::error('AwsSecretsService: getSecretValue failed', [
                'secret' => $secretName,
                'aws_error_code' => $e->getAwsErrorCode(),
                'aws_error_type' => $e->getAwsErrorType(),
                'status_code' => $e->getStatusCode(),
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException("Failed to retrieve secret '{$secretName}': " . $e->getMessage(), 0, $e);
        }

        if ($this->debug) {
            $safeMeta = [];
            foreach (['ARN', 'Name', 'VersionId', 'CreatedDate'] as $k) {
                if (isset($result[$k])) {
                    $safeMeta[$k] = (string) $result[$k];
                }
            }
            Log::info('AwsSecretsService: raw secret response (safe)', [
                'secret' => $secretName,
                'result_keys' => array_keys($result->toArray()),
                'meta' => $safeMeta,
                'has_SecretString' => isset($result['SecretString']),
                'has_SecretBinary' => isset($result['SecretBinary']),
            ]);
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

        // Primary decode attempt
        $decoded = json_decode($secretString, true);

        // Handle double-encoded JSON (SecretString is a JSON string containing JSON)
        if (is_string($decoded)) {
            $decoded2 = json_decode($decoded, true);
            if (is_array($decoded2)) {
                $decoded = $decoded2;
            }
        }

        if (! is_array($decoded)) {
            $looksJson = ltrim($secretString) !== '' && in_array(ltrim($secretString)[0], ['{', '[', '"'], true);
            Log::error('AwsSecretsService: SecretString did not decode to array', [
                'secret' => $secretName,
                'looks_like_json' => $looksJson,
                'json_error' => json_last_error_msg(),
                'secret_string_len' => strlen($secretString),
            ]);
            throw new \RuntimeException("Secret '{$secretName}' is not valid JSON or did not decode to an associative array.");
        }

        if ($this->debug) {
            Log::info('AwsSecretsService: decoded secret keys', [
                'secret' => $secretName,
                'keys' => array_keys($decoded),
                'redacted' => $this->redactForLog($decoded),
            ]);
        }

        // Cache and return
        $this->cache[$secretName] = $decoded;
        return $decoded;
    }

    private function redactForLog(array $value): array
    {
        $out = [];
        foreach ($value as $k => $v) {
            $key = strtolower((string) $k);
            if (in_array($key, ['password', 'secret', 'token', 'access_token', 'api_key', 'apikey', 'username'], true)) {
                $out[$k] = 'REDACTED';
                continue;
            }
            $out[$k] = is_array($v) ? $this->redactForLog($v) : $v;
        }
        return $out;
    }
}
