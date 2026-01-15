<?php

namespace App\Services;

use App\Exceptions\PbxwareClientException;
use App\Services\AwsSecretsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class PbxwareClient
{
    protected static bool $hasLoggedBaseUrlResolution = false;

    protected string $baseUrl;
    protected array $credentials;
    protected string $secretName = 'pbxware/api-credentials';
    protected int $secretTtlSeconds = 600; // 10 minutes
    protected ?AwsSecretsService $secretsService = null;

    public function __construct(?AwsSecretsService $secretsService = null)
    {
        // Determine mock mode strictly from environment variable only.
        // PBXWARE_MOCK_MODE is the single source of truth for mock vs real
        // PBX behaviour. Do not rely on APP_ENV or other config values.
        $mock = filter_var(env('PBXWARE_MOCK_MODE', false), FILTER_VALIDATE_BOOLEAN);
        if ($mock) {
            Log::info('PbxwareClient: operating in MOCK mode per PBXWARE_MOCK_MODE env var');
            $this->baseUrl = '';
            $this->credentials = [];
            return;
        }

        Log::info('PbxwareClient: operating in REAL mode per PBXWARE_MOCK_MODE env var');

        $this->secretsService = $secretsService ?? new AwsSecretsService();

        // Credential precedence:
        // 1) AWS Secrets Manager (production preferred)
        // 2) ENV fallback (ONLY if secret missing/unavailable or incomplete)
        $resolved = $this->resolveCredentials();
        $this->credentials = $resolved;

        $this->baseUrl = rtrim((string) ($resolved['base_url'] ?? ''), '/');

        // PBXware uses a query-based API on the host root. Base URL must NOT
        // contain an API path (e.g. /api/v7). Warn if an API-like path is present.
        if ($this->baseUrl && stripos($this->baseUrl, '/api/') !== false) {
            Log::warning('PbxwareClient: configured PBX base URL contains an /api/ path; PBXware expects root query endpoints (no /api/).', ['base_url' => $this->baseUrl]);
        }
    }

    /**
     * Resolve PBXware credentials using Secrets Manager as PRIMARY source
     * with ENV fallback for testing/recovery.
     *
     * Normalized structure:
     *   {
     *     api_key,
     *     base_url,
     *     server_id,
     *     timeout
     *   }
     */
    protected function resolveCredentials(): array
    {
        $secretCreds = $this->getCachedCredentials();
        try {
            $normalized = $this->normalizeAndValidateCredentials($secretCreds, 'secrets-manager');
            if (! self::$hasLoggedBaseUrlResolution) {
                Log::info('PBXware credentials resolved from Secrets Manager', [
                    'secret' => $this->secretName,
                    'base_url' => $normalized['base_url'] ?? null,
                    'server_id' => $normalized['server_id'] ?? null,
                    'timeout' => $normalized['timeout'] ?? null,
                ]);
                self::$hasLoggedBaseUrlResolution = true;
            }
            return $normalized;
        } catch (PbxwareClientException $e) {
            // Do NOT block execution if secrets are unreachable/misconfigured
            // but ENV fallback is present.
            Log::warning('PbxwareClient: Secrets Manager credentials unavailable or incomplete; attempting ENV fallback', [
                'secret' => $this->secretName,
                'error' => $e->getMessage(),
                'secret_keys' => is_array($secretCreds) ? array_keys($secretCreds) : [],
            ]);
        }

        $envCreds = $this->getEnvCredentials();
        try {
            $normalized = $this->normalizeAndValidateCredentials($envCreds, 'env');
            if (! self::$hasLoggedBaseUrlResolution) {
                Log::info('PBXware credentials resolved from ENV fallback', [
                    'base_url' => $normalized['base_url'] ?? null,
                    'server_id' => $normalized['server_id'] ?? null,
                    'timeout' => $normalized['timeout'] ?? null,
                ]);
                self::$hasLoggedBaseUrlResolution = true;
            }
            return $normalized;
        } catch (PbxwareClientException $e) {
            $msg = 'PBXware credentials missing: unable to load from AWS Secrets Manager secret "' . $this->secretName . '" and ENV fallback (PBXWARE_API_KEY, PBXWARE_BASE_URL, PBXWARE_SERVER_ID).';
            Log::error('PbxwareClient: ' . $msg, [
                'secret' => $this->secretName,
                'env_present' => [
                    'PBXWARE_API_KEY' => env('PBXWARE_API_KEY') ? true : false,
                    'PBXWARE_BASE_URL' => env('PBXWARE_BASE_URL') ? true : false,
                    'PBXWARE_SERVER_ID' => env('PBXWARE_SERVER_ID') ? true : false,
                    'PBXWARE_TIMEOUT' => env('PBXWARE_TIMEOUT') !== null,
                ],
                'error' => $e->getMessage(),
            ]);
            throw new PbxwareClientException($msg, 0, $e);
        }
    }

    /**
     * ENV fallback credential loader.
     * Only used when Secrets Manager is missing/unavailable or incomplete.
     */
    protected function getEnvCredentials(): array
    {
        $out = [];

        $apiKey = env('PBXWARE_API_KEY');
        $baseUrl = env('PBXWARE_BASE_URL');
        $serverId = env('PBXWARE_SERVER_ID');
        $timeout = env('PBXWARE_TIMEOUT');

        if ($apiKey !== null && trim((string) $apiKey) !== '') {
            $out['api_key'] = (string) $apiKey;
        }
        if ($baseUrl !== null && trim((string) $baseUrl) !== '') {
            $out['base_url'] = (string) $baseUrl;
        }
        if ($serverId !== null && trim((string) $serverId) !== '') {
            $out['server_id'] = (string) $serverId;
        }
        if ($timeout !== null && trim((string) $timeout) !== '') {
            $out['timeout'] = (int) $timeout;
        }

        return $out;
    }

    protected function getCachedCredentials(): array
    {
        $cacheKey = $this->cacheKey();
        $disableCache = filter_var(env('PBXWARE_DISABLE_SECRETS_CACHE', false), FILTER_VALIDATE_BOOLEAN);

        if ($disableCache) {
            Cache::forget($cacheKey);
        } else {
            // Self-heal: if a previous run cached an empty array, treat as a cache miss.
            if (Cache::has($cacheKey)) {
                $cached = Cache::get($cacheKey);
                if (is_array($cached) && $cached !== []) {
                    return $cached;
                }
            }
        }

        try {
            $decoded = $this->secretsService->get($this->secretName);
            if (is_array($decoded) && $decoded !== [] && ! $disableCache) {
                Cache::put($cacheKey, $decoded, $this->secretTtlSeconds);
            }
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            // IMPORTANT: do NOT cache failures/empty secrets.
            Log::error('PbxwareClient: failed to fetch secret via AwsSecretsService', [
                'secret' => $this->secretName,
                'cache_key' => $cacheKey,
                'disable_cache' => $disableCache,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    protected function cacheKey(): string
    {
        // v2: previous versions could cache an empty array on transient failures.
        return 'pbxware_api_credentials_v2';
    }

    /**
     * Normalize and validate PBXware credentials.
     *
     * Supported sources:
     * - secrets-manager: secret "pbxware/api-credentials" (production preferred)
     * - env: PBXWARE_* vars (fallback only)
     */
    protected function normalizeAndValidateCredentials(array $creds, string $source): array
    {
        // Normalize common variant key names from Secrets Manager.
        if (! isset($creds['api_key']) && isset($creds['apikey'])) {
            $creds['api_key'] = $creds['apikey'];
        }
        if (! isset($creds['base_url']) && isset($creds['baseUrl'])) {
            $creds['base_url'] = $creds['baseUrl'];
        }
        if (! isset($creds['server_id']) && isset($creds['server'])) {
            $creds['server_id'] = $creds['server'];
        }
        if (! isset($creds['server_id']) && isset($creds['serverId'])) {
            $creds['server_id'] = $creds['serverId'];
        }

        // Warn about unsupported legacy keys if present
        if (isset($creds['pbx_api_key']) || isset($creds['pbx_base_url'])) {
            Log::warning('PbxwareClient: unsupported secret keys found (pbx_api_key or pbx_base_url). These are not used. Use auth_type/access_token/api_key/token or username/password and base_url instead.', ['present_keys' => array_intersect_key($creds, array_flip(['pbx_api_key','pbx_base_url']))]);
        }

        // PBXware uses query-based auth ONLY.
        // If auth_type is provided and not "query", fail fast.
        $authType = strtolower((string) ($creds['auth_type'] ?? $creds['auth'] ?? 'query'));
        if ($authType !== 'query') {
            $msg = 'Unsupported PBXware auth_type: expected "query" for query-based API.';
            Log::error('PbxwareClient: ' . $msg, ['auth_type' => $authType, 'source' => $source]);
            throw new PbxwareClientException($msg);
        }

        $normalized = [
            'api_key' => $creds['api_key'] ?? null,
            'base_url' => $creds['base_url'] ?? null,
            'server_id' => $creds['server_id'] ?? null,
            'timeout' => isset($creds['timeout']) ? (int) $creds['timeout'] : (int) (config('pbx.providers.pbxware.timeout') ?? 30),
        ];

        // Required for query auth
        if (empty($normalized['api_key'])) {
            $msg = 'Credentials validation failed: api_key is missing.';
            Log::error('PbxwareClient: ' . $msg, ['available_keys' => array_keys($creds), 'source' => $source]);
            throw new PbxwareClientException($msg);
        }

        if (empty($normalized['base_url'])) {
            $msg = 'Credentials validation failed: base_url is missing.';
            Log::error('PbxwareClient: ' . $msg, ['available_keys' => array_keys($creds), 'source' => $source]);
            throw new PbxwareClientException($msg);
        }

        if (empty($normalized['server_id'])) {
            $msg = 'Credentials validation failed: server_id is missing.';
            Log::error('PbxwareClient: ' . $msg, ['available_keys' => array_keys($creds), 'source' => $source]);
            throw new PbxwareClientException($msg);
        }

        $normalized['api_key'] = (string) $normalized['api_key'];
        $normalized['base_url'] = rtrim((string) $normalized['base_url'], '/');
        $normalized['server_id'] = (string) $normalized['server_id'];

        return $normalized;
    }

    /**
     * Generic request wrapper with logging and error handling.
     * Returns Illuminate\Http\Client\Response on success or throws PbxwareClientException.
     */
    protected function sendRequest(string $method, string $path, array $params = [], array $options = [])
    {
        // For PBXware query-based API we treat $path as the `action` name.
        // Build the full query URL including apikey and server params.
        // Determine timeout: secret-provided timeout (seconds) or default 30s
        $timeout = (int) ($this->credentials['timeout'] ?? $options['timeout'] ?? 30);
        try {
            $start = microtime(true);
            $requestOptions = array_merge($options['guzzle'] ?? [], ['stream' => $options['stream'] ?? false, 'timeout' => $timeout]);

            // Build full query URL for PBXware (apikey/action/server + extra params)
            $url = $this->buildQueryUrl($path, $params);

            $request = Http::withOptions($requestOptions);

            // PBXware API is query-based and primarily uses GET for list/download
            $response = $request->get($url);

            // TEMP DIAGNOSTIC: full raw PBX response logging for visibility.
            // Always redact API keys if they ever appear.
            Log::info('PBXWARE_RAW_RESPONSE', [
                'action' => $path,
                'status' => $response->status(),
                'body' => $this->redactRawPbxResponseBody((string) $response->body()),
            ]);

            $latencyMs = round((microtime(true) - $start) * 1000, 2);

            // Redact apikey when logging URL
            $redactedUrl = $this->redactUrl($url);

            if ($response->failed()) {
                $logBody = $this->redactForLog($response->body());
                $logContext = ['method' => $method, 'url' => $redactedUrl, 'params' => $this->redactForLog($params), 'status' => $response->status(), 'body' => $logBody, 'latency_ms' => $latencyMs, 'action' => $path, 'server' => $params['server'] ?? null, 'base_url' => $this->baseUrl];
                if (! empty($options['account_id'])) {
                    $logContext['account_id'] = $options['account_id'];
                }
                Log::error('PbxwareClient: request failed', $logContext);
                throw new PbxwareClientException("PBX request failed with status {$response->status()}", $response->status());
            }

            $logContext = ['method' => $method, 'url' => $redactedUrl, 'status' => $response->status(), 'latency_ms' => $latencyMs, 'action' => $path, 'server' => $params['server'] ?? null, 'base_url' => $this->baseUrl];
            if (! empty($options['account_id'])) {
                $logContext['account_id'] = $options['account_id'];
            }
            Log::info('PbxwareClient: request succeeded', $logContext);

            return $response;
        } catch (PbxwareClientException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('PbxwareClient: exception during request', ['method' => $method, 'url' => $this->redactUrl($url ?? ''), 'base_url' => $this->baseUrl, 'timeout' => $timeout, 'error' => $e->getMessage()]);
            throw new PbxwareClientException('PBX request exception: ' . $e->getMessage() . ' (base_url=' . ($this->baseUrl ?? '[not set]') . ', timeout=' . $timeout . 's)', 0, $e);
        }
    }

    /**
     * Redact sensitive tokens from raw PBX responses, without otherwise changing the payload.
     */
    protected function redactRawPbxResponseBody(string $rawBody): string
    {
        // Query string style: apikey=...
        $rawBody = preg_replace('/(apikey=)([^&\s]+)/i', '$1REDACTED', $rawBody);

        // JSON style: "api_key":"..." or "apikey":"..."
        $rawBody = preg_replace('/("api_key"\s*:\s*")([^"]+)(")/i', '$1REDACTED$3', $rawBody);
        $rawBody = preg_replace('/("apikey"\s*:\s*")([^"]+)(")/i', '$1REDACTED$3', $rawBody);

        return is_string($rawBody) ? $rawBody : '';
    }

    /**
     * Build a full PBXware query URL. PBXware expects requests at the host
     * root using query parameters: ?apikey=API_KEY&action=...&server=ID
     * Additional params (date ranges, pagination) are appended as query params.
     */
    protected function buildQueryUrl(string $action, array $params = []): string
    {
        $base = rtrim($this->baseUrl ?? '', '/');
        $query = array_merge([
            'apikey' => $this->credentials['api_key'] ?? '',
            'action' => $action,
        ], $params);

        return $base . '/?' . http_build_query($query);
    }

    /**
     * Redact apikey values from a URL for safe logging.
     */
    protected function redactUrl(string $url): string
    {
        return preg_replace('/(apikey=)([^&]+)/i', '$1REDACTED', $url);
    }

    protected function redactForLog($value)
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                if (in_array(strtolower($k), ['password', 'secret', 'token', 'access_token', 'api_key', 'username'])) {
                    $out[$k] = 'REDACTED';
                } else {
                    $out[$k] = $this->redactForLog($v);
                }
            }
            return $out;
        }
        if (is_string($value) && strlen($value) > 1000) {
            return substr($value, 0, 1000) . '...';
        }
        return $value;
    }

    public function fetchCdrRecords(array $params): array|string
    {
        // Official contract: ONLY action=pbxware.cdr.download and ALWAYS status=8.
        // Only allow server/start/end/status params.
        $out = [];
        foreach (['server', 'start', 'end'] as $k) {
            if (array_key_exists($k, $params)) {
                $out[$k] = $params[$k];
            }
        }
        $out['status'] = 8;
        return $this->fetchAction('pbxware.cdr.download', $out);
    }

    public function fetchTranscription(array $params): array|string
    {
        // Official contract: server + uniqueid.
        $out = [];
        foreach (['server', 'uniqueid'] as $k) {
            if (array_key_exists($k, $params)) {
                $out[$k] = $params[$k];
            }
        }
        return $this->fetchAction('pbxware.transcription.get', $out);
    }

    /**
     * Fetch any PBXware query action dynamically.
     *
    * - Builds: base_url + /?apikey=...&action=...&{params}
     * - Supports JSON (returns array) and plain text (returns string)
     * - Logs action name + response type (json/text/empty)
     * - Throws PbxwareClientException on non-200 responses
     */
    public function fetchAction(string $action, array $params = []): array|string
    {
        $response = $this->sendRequest('GET', $action, $params);

        if ($response->status() !== 200) {
            $body = $this->redactForLog((string) $response->body());
            Log::error('PbxwareClient: non-200 response for action', [
                'action' => $action,
                'status' => $response->status(),
                'body' => $body,
            ]);
            throw new PbxwareClientException("PBX action {$action} failed with status {$response->status()}", $response->status());
        }

        $body = (string) $response->body();
        if (trim($body) === '') {
            Log::info('PbxwareClient: action response type', ['action' => $action, 'response_type' => 'empty']);
            return '';
        }

        $contentType = strtolower((string) ($response->header('Content-Type') ?? ''));

        // Prefer JSON detection via content-type or body heuristics.
        if (str_contains($contentType, 'json') || $this->looksLikeJson($body)) {
            $json = $response->json();
            $json = is_array($json) ? $json : [];
            Log::info('PbxwareClient: action response type', ['action' => $action, 'response_type' => 'json']);
            return $json;
        }

        // PBXware transcription endpoint may return plain text.
        $text = trim($body);
        Log::info('PbxwareClient: action response type', ['action' => $action, 'response_type' => 'text', 'len' => strlen($text)]);
        return $text;
    }

    protected function looksLikeJson(string $body): bool
    {
        $trim = ltrim($body);
        return $trim !== '' && ($trim[0] === '{' || $trim[0] === '[');
    }
}
