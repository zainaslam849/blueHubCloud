<?php

namespace App\Services;

use App\Exceptions\PbxwareClientException;
use App\Services\AwsSecretsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Psr\Http\Message\StreamInterface;

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

        $this->credentials = $this->getCachedCredentials();

        // PBX base URL is intentionally centralized in Secrets Manager.
        // Always load credentials from Secrets Manager and read base_url ONLY
        // from the `pbxware/api-credentials` secret.
        // Normalize and validate credentials retrieved from Secrets Manager.
        // This will throw a PbxwareClientException with a clear message
        // if required auth fields are missing.
        $this->credentials = $this->normalizeAndValidateCredentials($this->credentials);

        // PBX base URL is intentionally centralized in Secrets Manager.
        $this->baseUrl = rtrim((string) ($this->credentials['base_url'] ?? ''), '/');
        if (! self::$hasLoggedBaseUrlResolution) {
            Log::info('PBX base URL resolved from Secrets Manager');
            self::$hasLoggedBaseUrlResolution = true;
        }

        // PBXware uses a query-based API on the host root. Base URL must NOT
        // contain an API path (e.g. /api/v7). Warn if an API-like path is present.
        if ($this->baseUrl && stripos($this->baseUrl, '/api/') !== false) {
            Log::warning('PbxwareClient: configured PBX base URL contains an /api/ path; PBXware expects root query endpoints (no /api/).', ['base_url' => $this->baseUrl]);
        }
    }

    protected function getCachedCredentials(): array
    {
        return Cache::remember($this->cacheKey(), $this->secretTtlSeconds, function () {
            try {
                $decoded = $this->secretsService->get($this->secretName);
                return $decoded;
            } catch (\Throwable $e) {
                Log::error('PbxwareClient: failed to fetch secret via AwsSecretsService', ['secret' => $this->secretName, 'error' => $e->getMessage()]);
                return [];
            }
        });
    }

    protected function cacheKey(): string
    {
        return 'pbxware_api_credentials_v1';
    }

    /**
     * Normalize and validate credentials fetched from Secrets Manager.
     * Ensures one supported auth mechanism is present and returns a normalized
     * credential array. Throws PbxwareClientException on validation failure.
     */
    protected function normalizeAndValidateCredentials(array $creds): array
    {
        // Warn about unsupported legacy keys if present
        if (isset($creds['pbx_api_key']) || isset($creds['pbx_base_url'])) {
            Log::warning('PbxwareClient: unsupported secret keys found (pbx_api_key or pbx_base_url). These are not used. Use auth_type/access_token/api_key/token or username/password and base_url instead.', ['present_keys' => array_intersect_key($creds, array_flip(['pbx_api_key','pbx_base_url']))]);
        }

        // PBXware uses a query-based API. We require auth_type == 'query' and
        // the presence of both `api_key` and `server_id` in Secrets Manager.
        $normalized = $creds;
        $authType = strtolower($creds['auth_type'] ?? $creds['auth'] ?? 'query');
        $normalized['type'] = $authType;

        if (isset($creds['timeout'])) {
            $normalized['timeout'] = (int) $creds['timeout'];
        }

        if ($authType !== 'query') {
            $msg = 'Unsupported secrets auth_type: expected "query" for PBXware query-based API.';
            Log::error('PbxwareClient: ' . $msg, ['auth_type' => $authType]);
            throw new PbxwareClientException($msg);
        }

        // Required for query auth
        if (empty($creds['api_key'])) {
            $msg = 'Secrets validation failed: auth_type is "query" but api_key is missing.';
            Log::error('PbxwareClient: ' . $msg, ['available_keys' => array_keys($creds)]);
            throw new PbxwareClientException($msg);
        }
        if (empty($creds['server_id']) && ! isset($creds['server'])) {
            $msg = 'Secrets validation failed: auth_type is "query" but server_id is missing.';
            Log::error('PbxwareClient: ' . $msg, ['available_keys' => array_keys($creds)]);
            throw new PbxwareClientException($msg);
        }

        // PBX base URL is intentionally centralized in Secrets Manager.
        if (empty($creds['base_url'])) {
            $msg = 'Secrets validation failed: base_url is missing from the "pbxware/api-credentials" secret. PBX base URL is intentionally centralized in Secrets Manager.';
            Log::error('PbxwareClient: ' . $msg, ['available_keys' => array_keys($creds)]);
            throw new PbxwareClientException($msg);
        }

        $normalized['api_key'] = $creds['api_key'];
        $normalized['server_id'] = $creds['server_id'] ?? $creds['server'];
        $normalized['base_url'] = rtrim((string) $creds['base_url'], '/');

        return $normalized;
    }

    protected function buildHeaders(array $extra = []): array
    {
        $headers = array_merge([
            'Accept' => 'application/json',
        ], $extra);
        return $headers;
    }

    /**
     * Generic request wrapper with logging and error handling.
     * Returns Illuminate\Http\Client\Response on success or throws PbxwareClientException.
     */
    protected function sendRequest(string $method, string $path, array $params = [], array $options = [])
    {
        // For PBXware query-based API we treat $path as the `action` name.
        // Build the full query URL including apikey and server params.
        $headers = $this->buildHeaders($options['headers'] ?? []);

        // Determine timeout: secret-provided timeout (seconds) or default 30s
        $timeout = (int) ($this->credentials['timeout'] ?? $options['timeout'] ?? 30);
        try {
            $start = microtime(true);
            $requestOptions = array_merge($options['guzzle'] ?? [], ['stream' => $options['stream'] ?? false, 'timeout' => $timeout]);

            // Build full query URL for PBXware (apikey/action/server + extra params)
            $url = $this->buildQueryUrl($path, $params);

            $request = Http::withHeaders($headers)->withOptions($requestOptions);

            // PBXware API is query-based and primarily uses GET for list/download
            $response = $request->get($url);

            $latencyMs = round((microtime(true) - $start) * 1000, 2);

            // Redact apikey when logging URL
            $redactedUrl = $this->redactUrl($url);

            if ($response->failed()) {
                $logBody = $this->redactForLog($response->body());
                $logContext = ['method' => $method, 'url' => $redactedUrl, 'params' => $this->redactForLog($params), 'status' => $response->status(), 'body' => $logBody, 'latency_ms' => $latencyMs, 'action' => $path, 'server_id' => $this->credentials['server_id'] ?? null, 'base_url' => $this->baseUrl];
                if (! empty($options['account_id'])) {
                    $logContext['account_id'] = $options['account_id'];
                }
                Log::error('PbxwareClient: request failed', $logContext);
                throw new PbxwareClientException("PBX request failed with status {$response->status()}", $response->status());
            }

            $logContext = ['method' => $method, 'url' => $redactedUrl, 'status' => $response->status(), 'latency_ms' => $latencyMs, 'action' => $path, 'server_id' => $this->credentials['server_id'] ?? null, 'base_url' => $this->baseUrl];
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
            'server' => $this->credentials['server_id'] ?? '',
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

    protected function buildUrl(string $path): string
    {
        $path = ltrim($path, '/');
        return $this->baseUrl ? ($this->baseUrl . '/' . $path) : $path;
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

    /**
     * Fetch calls from PBX. Handles basic pagination.
     * Returns an array of items.
     */
    public function fetchCalls(array $params = []): array
    {
        // PBXware CDR ingestion is implemented via CSV export on pbxware.cdr.download.
        // This legacy helper is not used by the ingestion pipeline.
        Log::warning('PbxwareClient: fetchCalls is deprecated; use fetchCdrCsv instead.');
        return [];
    }

    /**
     * Fetch recordings from PBX. Handles basic pagination.
     */
    public function fetchRecordings(array $params = []): array
    {
        // Recording downloads are performed via pbxware.cdr.download with recording=<path>.
        // This legacy helper is not used by the ingestion pipeline.
        Log::warning('PbxwareClient: fetchRecordings is deprecated; use fetchActionStream(pbxware.cdr.download, ...) instead.');
        return [];
    }

    /**
     * Generic paginated fetch. Tries to detect pagination tokens or next links.
     */
    protected function fetchPaginated(string $path, array $params = [], int $maxPages = 100): array
    {
        $results = [];
        $page = 1;

        while (true) {
            $params['page'] = $params['page'] ?? $page;
            // $path is the PBXware `action` name.
            $response = $this->sendRequest('GET', $path, $params);
            $json = $response->json();

            // Try common locations for items
            if (isset($json['data']) && is_array($json['data'])) {
                $items = $json['data'];
            } elseif (isset($json['items']) && is_array($json['items'])) {
                $items = $json['items'];
            } elseif (is_array($json)) {
                $items = $json;
            } else {
                $items = [];
            }

            // If items is associative object that is not list, wrap it
            if ($items !== [] && array_values($items) !== $items) {
                // associative array: treat as single item
                $results[] = $items;
            } else {
                $results = array_merge($results, $items);
            }

            // Detect next token or page
            $next = null;
            if (isset($json['next']) && $json['next']) {
                $next = $json['next'];
            } elseif (isset($json['meta']['next']) && $json['meta']['next']) {
                $next = $json['meta']['next'];
            } elseif (isset($json['links']['next']) && $json['links']['next']) {
                $next = $json['links']['next'];
            } elseif (isset($json['meta']['page']) && isset($json['meta']['total_pages'])) {
                $current = (int)$json['meta']['page'];
                $total = (int)$json['meta']['total_pages'];
                if ($current < $total) {
                    $next = $current + 1;
                }
            }

            // If next is numeric, increment page
            if ($next === null) {
                // fallback: if items empty or less than page size, stop
                if (empty($items)) {
                    break;
                }
                // try naive single-page behavior
                break;
            }

            $page++;
            if ($page > $maxPages) {
                Log::warning('PbxwareClient: reached max pages during pagination', ['path' => $path, 'max_pages' => $maxPages]);
                break;
            }
        }

        return $results;
    }

    /**
     * Download a recording as a streamed response. The response streams directly from PBX through the server to the caller.
     * Returns Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function downloadRecording($params): StreamedResponse
    {
        // Accept either a string id (legacy) or an array of query params.
        if (is_string($params)) {
            $params = ['recording_id' => $params];
        }
        if (! is_array($params)) {
            throw new PbxwareClientException('downloadRecording expects a recording id string or an array of query parameters');
        }
        return $this->downloadRecordingByParams($params);
    }

    /**
     * Fetch CDR rows via PBXware CSV export.
     *
     * Authoritative rule: use action=pbxware.cdr.download with export=1.
     * Returns raw [header, rows] numeric arrays.
     */
    public function fetchCdrCsv(array $params = []): array
    {
        if (! array_key_exists('export', $params)) {
            $params['export'] = 1;
        }

        $response = $this->sendRequest('GET', 'pbxware.cdr.download', $params);
        if ($response->failed()) {
            $status = $response->status();
            $body = $this->redactForLog($response->body());
            Log::error('PbxwareClient: fetchCdrCsv failed', ['action' => 'pbxware.cdr.download', 'server_id' => $this->credentials['server_id'] ?? null, 'status' => $status, 'body' => $body]);
            throw new PbxwareClientException("Failed to fetch CDR CSV, status {$status}");
        }

        // PBXware CDR list responses are CSV. Return raw header + row arrays
        // exactly as received (no associative remapping).
        $csv = (string) $response->body();
        [$header, $rows] = $this->parseCsv($csv);

        return [
            'header' => $header,
            'rows' => $rows,
        ];
    }

    /**
     * Backward-compatible alias; do not use in new code.
     */
    public function fetchCdrList(array $params = []): array
    {
        Log::warning('PbxwareClient: fetchCdrList is deprecated; use fetchCdrCsv instead.');
        return $this->fetchCdrCsv($params);
    }

    /**
     * Parse a CSV string into [header, rows] where both are numeric arrays.
     * Preserves raw cell strings as received.
     *
     * @return array{0: array<int,string>, 1: array<int, array<int,string>>}
     */
    protected function parseCsv(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $header = [];
        $rows = [];
        $line = 0;
        while (($data = fgetcsv($handle)) !== false) {
            // Skip completely empty lines
            if ($data === [null] || $data === []) {
                continue;
            }

            // Normalize nulls to empty strings to keep array shape predictable
            $data = array_map(static function ($v) {
                return $v === null ? '' : (string) $v;
            }, $data);

            if ($line === 0) {
                $header = $data;
            } else {
                $rows[] = $data;
            }
            $line++;
        }

        fclose($handle);
        return [$header, $rows];
    }

    /**
     * Fetch any PBXware query action dynamically.
     *
     * - Builds: base_url + /?apikey=...&action=...&server=...&{params}
     * - Supports JSON (returns array) and CSV (returns ['header'=>..., 'rows'=>...])
     * - Logs action name + response type (json/csv/empty)
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

        // Default to CSV for PBXware export-style responses.
        [$header, $rows] = $this->parseCsv($body);
        Log::info('PbxwareClient: action response type', ['action' => $action, 'response_type' => 'csv', 'rows' => count($rows)]);

        return [
            'header' => $header,
            'rows' => $rows,
        ];
    }

    /**
     * Streaming variant for actions that return binary bodies (e.g. downloads).
     * Returns a PSR-7 stream; caller can pipe directly to S3.
     */
    public function fetchActionStream(string $action, array $params = []): StreamInterface
    {
        $response = $this->sendRequest('GET', $action, $params, ['stream' => true]);

        if ($response->status() !== 200) {
            $body = $this->redactForLog((string) $response->body());
            Log::error('PbxwareClient: non-200 stream response for action', [
                'action' => $action,
                'status' => $response->status(),
                'body' => $body,
            ]);
            throw new PbxwareClientException("PBX action {$action} failed with status {$response->status()}", $response->status());
        }

        Log::info('PbxwareClient: action response type', ['action' => $action, 'response_type' => 'stream']);
        return $response->toPsrResponse()->getBody();
    }

    /**
     * Probe a small SAFE list of known read-only PBXware actions.
     * Never throws; returns structured diagnostics.
     */
    public function testAvailableActions(): array
    {
        $actions = [
            'pbxware.cdr.download',
        ];

        $results = [];
        foreach ($actions as $action) {
            try {
                $result = $this->fetchAction($action, ['limit' => 1, 'export' => 1]);
                $responseType = $this->inferResponseTypeFromResult($result);

                if ($this->resultLooksLikeInvalidAction($result)) {
                    $results[$action] = [
                        'status' => 'invalid_action',
                        'response_type' => $responseType,
                        'rows' => 0,
                    ];
                    continue;
                }

                $rowCount = $this->countRowsInResult($result);
                $results[$action] = [
                    'status' => $rowCount > 0 ? 'success' : 'empty',
                    'response_type' => $responseType,
                    'rows' => $rowCount,
                ];
            } catch (PbxwareClientException $e) {
                $results[$action] = [
                    'status' => 'invalid_action',
                    'response_type' => 'error',
                    'rows' => 0,
                    'error' => $e->getMessage(),
                ];
            } catch (\Throwable $e) {
                $results[$action] = [
                    'status' => 'invalid_action',
                    'response_type' => 'error',
                    'rows' => 0,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'tested' => $actions,
            'results' => $results,
        ];
    }

    protected function looksLikeJson(string $body): bool
    {
        $trim = ltrim($body);
        return $trim !== '' && ($trim[0] === '{' || $trim[0] === '[');
    }

    /**
     * Attempt to discover available PBXware actions using documented-style patterns.
     *
     * Tries a small allow-list of discovery actions and returns the first
     * successful parsed list of action names.
     *
     * Logs HTTP status + raw response body (apikey redacted).
     */
    public function discoverActions(): array
    {
        $candidates = [
            'pbxware.api.list',
            'pbxware.action.list',
            'pbxware.system.actions',
            'pbxware.help',
        ];

        foreach ($candidates as $action) {
            try {
                $url = $this->buildQueryUrl($action, []);
                $response = $this->sendRequest('GET', $action, []);

                $status = $response->status();
                $body = (string) $response->body();
                $bodyRedacted = $this->redactDiscoverBody($body);

                Log::info('PbxwareClient: discoverActions attempt', [
                    'action' => $action,
                    'status' => $status,
                    'url' => $this->redactUrl($url),
                    'raw_response' => $this->truncateForLog($bodyRedacted, 8000),
                ]);

                if ($status !== 200) {
                    continue;
                }

                if ($this->responseIsInvalidAction($body)) {
                    continue;
                }

                $actions = $this->parseActionNamesFromBody($body);
                if (! empty($actions)) {
                    return $actions;
                }
            } catch (PbxwareClientException $e) {
                Log::info('PbxwareClient: discoverActions attempt failed', [
                    'action' => $action,
                    'error' => $e->getMessage(),
                ]);
                continue;
            } catch (\Throwable $e) {
                Log::info('PbxwareClient: discoverActions attempt errored', [
                    'action' => $action,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        return [];
    }

    protected function responseIsInvalidAction(string $body): bool
    {
        return stripos($body, 'Action method is invalid') !== false
            || stripos($body, 'invalid action') !== false;
    }

    protected function redactDiscoverBody(string $body): string
    {
        // Best-effort: redact the api_key value if it appears.
        $apiKey = (string) ($this->credentials['api_key'] ?? '');
        if ($apiKey !== '') {
            $body = str_replace($apiKey, 'REDACTED', $body);
        }

        // Also redact query-string apikey patterns.
        $body = preg_replace('/(apikey=)([^&\s]+)/i', '$1REDACTED', $body);

        return $body;
    }

    protected function truncateForLog(string $s, int $maxBytes): string
    {
        if (strlen($s) <= $maxBytes) {
            return $s;
        }
        return substr($s, 0, $maxBytes) . '...';
    }

    /**
     * Parse a list of action names from JSON/text bodies.
     * Does not assume any particular response format.
     */
    protected function parseActionNamesFromBody(string $body): array
    {
        $bodyTrim = trim($body);
        if ($bodyTrim === '') {
            return [];
        }

        // JSON formats
        if ($this->looksLikeJson($bodyTrim)) {
            $decoded = json_decode($bodyTrim, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $this->extractActionNamesFromJson($decoded);
            }
        }

        // Text formats: look for tokens that resemble pbxware.*.*
        $tokens = preg_split('/[\r\n\t,;\s]+/', $bodyTrim);
        if (! is_array($tokens)) {
            return [];
        }

        $actions = [];
        foreach ($tokens as $t) {
            $t = trim((string) $t);
            if ($t === '') {
                continue;
            }
            if (preg_match('/^pbxware\.[a-z0-9_]+\.[a-z0-9_]+$/i', $t)) {
                $actions[] = $t;
            }
        }

        return array_values(array_unique($actions));
    }

    protected function extractActionNamesFromJson($decoded): array
    {
        $out = [];

        if (is_string($decoded)) {
            return [];
        }

        // If it's a list of strings
        if (is_array($decoded) && array_values($decoded) === $decoded) {
            foreach ($decoded as $item) {
                if (is_string($item) && preg_match('/^pbxware\./i', $item)) {
                    $out[] = $item;
                } elseif (is_array($item)) {
                    foreach (['action', 'name', 'method'] as $k) {
                        if (isset($item[$k]) && is_string($item[$k]) && preg_match('/^pbxware\./i', $item[$k])) {
                            $out[] = $item[$k];
                        }
                    }
                }
            }
            return array_values(array_unique($out));
        }

        // Common wrappers
        if (is_array($decoded)) {
            foreach (['actions', 'data', 'items', 'result'] as $k) {
                if (isset($decoded[$k])) {
                    $nested = $this->extractActionNamesFromJson($decoded[$k]);
                    if (! empty($nested)) {
                        return $nested;
                    }
                }
            }
        }

        return [];
    }

    protected function inferResponseTypeFromResult(array|string $result): string
    {
        if (is_string($result)) {
            return trim($result) === '' ? 'empty' : 'string';
        }

        if (isset($result['header'], $result['rows']) && is_array($result['rows'])) {
            return 'csv';
        }

        return 'json';
    }

    protected function countRowsInResult(array|string $result): int
    {
        if (is_string($result)) {
            return 0;
        }

        if (isset($result['rows']) && is_array($result['rows'])) {
            return count($result['rows']);
        }

        // JSON-style arrays can either be a list or wrapped in data/items.
        if (isset($result['data']) && is_array($result['data'])) {
            return count($result['data']);
        }
        if (isset($result['items']) && is_array($result['items'])) {
            return count($result['items']);
        }

        // If it's a list, count it.
        return (array_values($result) === $result) ? count($result) : 0;
    }

    protected function resultLooksLikeInvalidAction(array|string $result): bool
    {
        if (is_string($result)) {
            return stripos($result, 'invalid action') !== false;
        }

        foreach (['error', 'message', 'msg', 'status'] as $key) {
            if (isset($result[$key]) && is_string($result[$key]) && stripos($result[$key], 'invalid action') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Simple connectivity test against PBXware (lists extensions).
     */
    public function testConnection(): array
    {
        $response = $this->sendRequest('GET', 'pbxware.ext.list', []);
        if ($response->failed()) {
            $status = $response->status();
            $body = $this->redactForLog($response->body());
            Log::error('PbxwareClient: testConnection failed', ['action' => 'pbxware.ext.list', 'server_id' => $this->credentials['server_id'] ?? null, 'status' => $status, 'body' => $body]);
            throw new PbxwareClientException("PBX testConnection failed, status {$status}");
        }
        return $response->json() ?: [];
    }

    /**
     * Preferable: download recording by passing an array of query params.
     * Builds ?apikey=...&action=pbxware.cdr.download&server=...&{params}
     */
    public function downloadRecordingByParams(array $params): StreamedResponse
    {
        $action = 'pbxware.cdr.download';
        $headers = $this->buildHeaders();
        $url = $this->buildQueryUrl($action, $params);

        // Choose a filename hint from params when available
        $filenameHint = $params['recording_id'] ?? $params['id'] ?? ($params['file'] ?? 'recording');

        return new StreamedResponse(function () use ($url, $headers, $filenameHint) {
            try {
                $response = Http::withHeaders($headers)->withOptions(['stream' => true])->get($url);
                if ($response->failed()) {
                    $status = $response->status();
                    $body = $this->redactForLog($response->body());
                    Log::error('PbxwareClient: downloadRecording failed', ['url' => $this->redactUrl($url), 'status' => $status, 'body' => $body, 'action' => 'pbxware.cdr.download', 'server_id' => $this->credentials['server_id'] ?? null]);
                    throw new PbxwareClientException("Failed to download recording, status {$status}");
                }

                $psr = $response->toPsrResponse();
                $body = $psr->getBody();
                while (! $body->eof()) {
                    echo $body->read(1024 * 16);
                    if (function_exists('fastcgi_finish_request')) {
                        @flush();
                    } else {
                        @flush();
                        @ob_flush();
                    }
                }
            } catch (\Throwable $e) {
                Log::error('PbxwareClient: exception streaming recording', ['error' => $e->getMessage()]);
                throw $e;
            }
        }, 200, $this->downloadHeaders($filenameHint));
    }

    /**
     * Return the underlying PSR-7 stream for a recording download.
     * Useful for server-side streaming to other sinks (S3).
     * Returns \Psr\Http\Message\StreamInterface on success or throws PbxwareClientException.
     */
    public function downloadRecordingStream(string $recordingId)
    {
        // Map to pbxware.cdr.download action and return PSR stream
        $params = ['recording_id' => $recordingId];
        $response = $this->sendRequest('GET', 'pbxware.cdr.download', $params, ['stream' => true]);

        if ($response->failed()) {
            $status = $response->status();
            $body = $this->redactForLog($response->body());
            Log::error('PbxwareClient: downloadRecordingStream failed', ['recording_id' => $recordingId, 'status' => $status, 'body' => $body, 'action' => 'pbxware.cdr.download']);
            throw new PbxwareClientException("Failed to download recording, status {$status}");
        }

        $psr = $response->toPsrResponse();
        return $psr->getBody();
    }

    /**
     * Official PBXware contract: pbxware.cdr.download uses recording=<recording_path>
     * where recording_path comes from CDR CSV row[8].
     */
    public function downloadRecordingStreamByRecordingPath(string $recordingPath)
    {
        $params = ['recording' => $recordingPath];
        $response = $this->sendRequest('GET', 'pbxware.cdr.download', $params, ['stream' => true]);

        if ($response->failed()) {
            $status = $response->status();
            $body = $this->redactForLog($response->body());
            Log::error('PbxwareClient: downloadRecordingStreamByRecordingPath failed', [
                'status' => $status,
                'body' => $body,
                'action' => 'pbxware.cdr.download',
            ]);
            throw new PbxwareClientException("Failed to download recording, status {$status}");
        }

        return $response->toPsrResponse()->getBody();
    }

    protected function downloadHeaders(string $recordingId): array
    {
        // Allow caller to override if needed. Provide sensible defaults.
        return [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="recording-' . $recordingId . '.mp3"',
        ];
    }
}
