<?php

namespace App\Services;

use App\Exceptions\PbxwareClientException;
use App\Services\AwsSecretsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PbxwareClient
{
    protected string $baseUrl;
    protected array $credentials;
    protected string $secretName = 'pbxware/api-credentials';
    protected int $secretTtlSeconds = 600; // 10 minutes
    protected ?AwsSecretsService $secretsService = null;

    public function __construct(?string $baseUrl = null, ?AwsSecretsService $secretsService = null)
    {
        $this->baseUrl = $baseUrl ? rtrim($baseUrl, '/') : rtrim(Config::get('services.pbxware.base_url', ''), '/');
        if (empty($this->baseUrl)) {
            Log::warning('PbxwareClient: services.pbxware.base_url is empty');
        }

        $this->secretsService = $secretsService ?? new AwsSecretsService();

        // Determine mock mode strictly from environment variable only.
        // PBXWARE_MOCK_MODE is the single source of truth for mock vs real
        // PBX behaviour. Do not rely on APP_ENV or other config values.
        $mock = filter_var(env('PBXWARE_MOCK_MODE', false), FILTER_VALIDATE_BOOLEAN);
        if ($mock) {
            Log::info('PbxwareClient: operating in MOCK mode per PBXWARE_MOCK_MODE env var');
            $this->credentials = [];
            return;
        }

        Log::info('PbxwareClient: operating in REAL mode per PBXWARE_MOCK_MODE env var');

        $this->credentials = $this->getCachedCredentials();

        // Allow secret to provide base_url and override if present
        if (! empty($this->credentials['base_url'])) {
            $this->baseUrl = rtrim($this->credentials['base_url'], '/');
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

    protected function buildHeaders(array $extra = []): array
    {
        $headers = array_merge([
            'Accept' => 'application/json',
        ], $extra);

        // Add Authorization header based on secret-provided auth_type
        $authType = strtolower($this->credentials['auth_type'] ?? $this->credentials['auth'] ?? 'bearer');
        if ($authType === 'basic' && isset($this->credentials['username']) && isset($this->credentials['password'])) {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->credentials['username'] . ':' . $this->credentials['password']);
        } elseif (($authType === 'bearer' || $authType === 'token') && ! empty($this->credentials['api_key'] ?? $this->credentials['token'] ?? $this->credentials['access_token'])) {
            $token = $this->credentials['api_key'] ?? $this->credentials['token'] ?? $this->credentials['access_token'];
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        // Allow callers to explicitly pass Authorization via $extra
        if (isset($extra['Authorization'])) {
            $headers['Authorization'] = $extra['Authorization'];
        }

        return $headers;
    }

    /**
     * Generic request wrapper with logging and error handling.
     * Returns Illuminate\Http\Client\Response on success or throws PbxwareClientException.
     */
    protected function sendRequest(string $method, string $path, array $params = [], array $options = [])
    {
        $url = $this->buildUrl($path);
        $headers = $this->buildHeaders($options['headers'] ?? []);

        try {
            $start = microtime(true);

            // Determine timeout: secret-provided timeout (seconds) or default 30s
            $timeout = (int) ($this->credentials['timeout'] ?? $options['timeout'] ?? 30);

            $requestOptions = array_merge($options['guzzle'] ?? [], ['stream' => $options['stream'] ?? false, 'timeout' => $timeout]);

            $request = Http::withHeaders($headers)->withOptions($requestOptions);

            if (strtoupper($method) === 'GET') {
                $response = $request->get($url, $params);
            } else {
                $response = $request->{$method}($url, $params);
            }

            $latencyMs = round((microtime(true) - $start) * 1000, 2);

            if ($response->failed()) {
                $logBody = $this->redactForLog($response->body());
                $logContext = ['method' => $method, 'url' => $url, 'params' => $this->redactForLog($params), 'status' => $response->status(), 'body' => $logBody, 'latency_ms' => $latencyMs];
                if (! empty($options['account_id'])) {
                    $logContext['account_id'] = $options['account_id'];
                }
                Log::error('PbxwareClient: request failed', $logContext);
                throw new PbxwareClientException("PBX request failed with status {$response->status()}", $response->status());
            }

            $logContext = ['method' => $method, 'url' => $url, 'status' => $response->status(), 'latency_ms' => $latencyMs];
            if (! empty($options['account_id'])) {
                $logContext['account_id'] = $options['account_id'];
            }
            Log::info('PbxwareClient: request succeeded', $logContext);

            return $response;
        } catch (PbxwareClientException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('PbxwareClient: exception during request', ['method' => $method, 'url' => $url, 'error' => $e->getMessage()]);
            throw new PbxwareClientException('PBX request exception: ' . $e->getMessage(), 0, $e);
        }
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
        return $this->fetchPaginated('/calls', $params);
    }

    /**
     * Fetch recordings from PBX. Handles basic pagination.
     */
    public function fetchRecordings(array $params = []): array
    {
        return $this->fetchPaginated('/recordings', $params);
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
    public function downloadRecording(string $recordingId): StreamedResponse
    {
        $path = '/recordings/' . rawurlencode($recordingId) . '/download';

        $headers = $this->buildHeaders();
        $url = $this->buildUrl($path);

        return new StreamedResponse(function () use ($url, $headers, $recordingId) {
            try {
                // Use Laravel HTTP client but access underlying PSR response to stream
                $response = Http::withHeaders($headers)->withOptions(['stream' => true])->get($url);
                if ($response->failed()) {
                    $status = $response->status();
                    $body = $this->redactForLog($response->body());
                    Log::error('PbxwareClient: downloadRecording failed', ['url' => $url, 'status' => $status, 'body' => $body]);
                    throw new PbxwareClientException("Failed to download recording, status {$status}");
                }

                $psr = $response->toPsrResponse();
                $body = $psr->getBody();
                // stream chunks
                while (! $body->eof()) {
                    echo $body->read(1024 * 16);
                    // flush to the client
                    if (function_exists('fastcgi_finish_request')) {
                        @flush();
                    } else {
                        @flush();
                        @ob_flush();
                    }
                }
            } catch (\Throwable $e) {
                Log::error('PbxwareClient: exception streaming recording', ['recording_id' => $recordingId, 'error' => $e->getMessage()]);
                // rethrow so caller can set HTTP status appropriately
                throw $e;
            }
        }, 200, $this->downloadHeaders($recordingId));
    }

    /**
     * Return the underlying PSR-7 stream for a recording download.
     * Useful for server-side streaming to other sinks (S3).
     * Returns \Psr\Http\Message\StreamInterface on success or throws PbxwareClientException.
     */
    public function downloadRecordingStream(string $recordingId)
    {
        $path = '/recordings/' . rawurlencode($recordingId) . '/download';
        $response = $this->sendRequest('GET', $path, [], ['stream' => true]);

        if ($response->failed()) {
            $status = $response->status();
            $body = $this->redactForLog($response->body());
            Log::error('PbxwareClient: downloadRecordingStream failed', ['recording_id' => $recordingId, 'status' => $status, 'body' => $body]);
            throw new PbxwareClientException("Failed to download recording, status {$status}");
        }

        $psr = $response->toPsrResponse();
        return $psr->getBody();
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
