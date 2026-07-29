<?php

namespace App\Services;

use App\Exceptions\PbxwareClientException;
use App\Models\PbxProvider;
use App\Services\AwsSecretsService;
use App\Services\Pbx\PbxCredentialResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class PbxwareClient
{
    protected string $baseUrl;
    protected array $credentials;
    protected ?PbxProvider $server = null;

    public function __construct(?PbxProvider $server = null, ?AwsSecretsService $secretsService = null)
    {
        // Determine mock mode strictly from environment variable only.
        // PBXWARE_MOCK_MODE is the single source of truth for mock vs real
        // PBX behaviour. Do not rely on APP_ENV or other config values.
        $mock = filter_var(config('services.pbxware.mock_mode', false), FILTER_VALIDATE_BOOLEAN);
        if ($mock) {
            Log::info('PbxwareClient: operating in MOCK mode per PBXWARE_MOCK_MODE env var');
            $this->baseUrl = '';
            $this->credentials = [];
            return;
        }

        // Null server = legacy/default server (pre-multi-server call sites).
        $this->server = $server ?? PbxProvider::defaultServer();
        if ($this->server === null) {
            throw new PbxwareClientException('No PBX server configured: pbx_providers has no default row.');
        }

        Log::info('PbxwareClient: operating in REAL mode', [
            'pbx_provider_id' => $this->server->id,
            'server' => $this->server->slug,
        ]);

        $this->credentials = PbxCredentialResolver::resolve($this->server, $secretsService);
        $this->baseUrl = rtrim((string) ($this->credentials['base_url'] ?? ''), '/');

        // PBXware uses a query-based API on the host root. Base URL must NOT
        // contain an API path (e.g. /api/v7). Warn if an API-like path is present.
        if ($this->baseUrl && stripos($this->baseUrl, '/api/') !== false) {
            Log::warning('PbxwareClient: configured PBX base URL contains an /api/ path; PBXware expects root query endpoints (no /api/).', ['base_url' => $this->baseUrl]);
        }
    }

    /**
     * The server this client is bound to (null only in mock mode).
     */
    public function server(): ?PbxProvider
    {
        return $this->server;
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
        // Connection timeout: fail fast on unreachable hosts while allowing
        // longer read timeout for large successful responses.
        $defaultConnectTimeout = min(max($timeout, 5), 10);
        $connect_timeout = (int) ($this->credentials['connect_timeout'] ?? $options['connect_timeout'] ?? $defaultConnectTimeout);
        try {
            $start = microtime(true);
            $requestOptions = array_merge($options['guzzle'] ?? [], [
                'stream' => $options['stream'] ?? false, 
                'timeout' => $timeout,
                'connect_timeout' => $connect_timeout,
            ]);

            // Build full query URL for PBXware (apikey/action/server + extra params)
            $url = $this->buildQueryUrl($path, $params);

            $request = Http::withHeaders($headers)->withOptions($requestOptions);

            // PBXware API is query-based and primarily uses GET for list/download
            $response = $request->get($url);

            $latencyMs = round((microtime(true) - $start) * 1000, 2);

            // Default: log only metadata. Full body logging is gated behind
            // config('pbx.debug_payloads') AND non-production environments,
            // since PBX response bodies can contain call/transcription data.
            if ($this->shouldLogPayloads()) {
                Log::info('PBXWARE_RAW_RESPONSE', [
                    'action' => $path,
                    'status' => $response->status(),
                    'latency_ms' => $latencyMs,
                    'body' => $this->redactRawPbxResponseBody((string) $response->body()),
                ]);
            } else {
                Log::info('PbxwareClient: response', [
                    'action' => $path,
                    'status' => $response->status(),
                    'latency_ms' => $latencyMs,
                    'body_length' => strlen((string) $response->body()),
                ]);
            }

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
            Log::error('PbxwareClient: exception during request', ['method' => $method, 'url' => $this->redactUrl($url ?? ''), 'base_url' => $this->baseUrl, 'timeout' => $timeout, 'connect_timeout' => $connect_timeout, 'error' => $e->getMessage()]);
            throw new PbxwareClientException('PBX request exception: ' . $e->getMessage() . ' (base_url=' . ($this->baseUrl ?? '[not set]') . ', timeout=' . $timeout . 's, connect_timeout=' . $connect_timeout . 's)', 0, $e);
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

    /**
     * True only when full PBX payload bodies should be written to logs.
     * Gated by config('pbx.debug_payloads') AND non-production environment.
     */
    protected function shouldLogPayloads(): bool
    {
        return (bool) config('pbx.debug_payloads', false)
            && app()->environment() !== 'production';
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
        // Targeted logging: record server + uniqueid + redacted request URL
        $server = $out['server'] ?? null;
        $uniqueid = $out['uniqueid'] ?? null;
        $url = $this->buildQueryUrl('pbxware.transcription.get', $out);
        Log::info('PbxwareClient: transcription.request', ['server' => $server, 'uniqueid' => $uniqueid, 'url' => $this->redactUrl($url)]);

        try {
            $response = $this->sendRequest('GET', 'pbxware.transcription.get', $out);
        } catch (PbxwareClientException $e) {
            Log::error('PbxwareClient: transcription.request.failed', ['server' => $server, 'uniqueid' => $uniqueid, 'error' => $e->getMessage()]);
            throw $e;
        }

        // Capture a short redacted preview of the raw PBX response only when
        // debug payload logging is enabled AND the app is not in production.
        $rawBody = (string) $response->body();
        if ($this->shouldLogPayloads()) {
            $preview = substr($this->redactRawPbxResponseBody($rawBody), 0, 200);
            Log::info('PbxwareClient: transcription.response_preview', ['server' => $server, 'uniqueid' => $uniqueid, 'preview' => $preview, 'len' => strlen($rawBody), 'status' => $response->status()]);
        } else {
            Log::info('PbxwareClient: transcription.response_meta', ['server' => $server, 'uniqueid' => $uniqueid, 'len' => strlen($rawBody), 'status' => $response->status()]);
        }

        // Parse and return following the existing fetchAction semantics
        if ($response->status() !== 200) {
            $body = $this->redactForLog($rawBody);
            Log::error('PbxwareClient: non-200 transcription response', ['status' => $response->status(), 'body' => $body, 'server' => $server, 'uniqueid' => $uniqueid]);
            throw new PbxwareClientException("PBX transcription request failed with status {$response->status()}", $response->status());
        }

        if (trim($rawBody) === '') {
            Log::info('PbxwareClient: transcription response type', ['response_type' => 'empty', 'server' => $server, 'uniqueid' => $uniqueid]);
            return '';
        }

        $contentType = strtolower((string) ($response->header('Content-Type') ?? ''));
        if (str_contains($contentType, 'json') || $this->looksLikeJson($rawBody)) {
            $json = $response->json();
            $json = is_array($json) ? $json : [];
            Log::info('PbxwareClient: transcription response type', ['response_type' => 'json', 'server' => $server, 'uniqueid' => $uniqueid]);
            return $json;
        }

        $text = trim($rawBody);
        Log::info('PbxwareClient: transcription response type', ['response_type' => 'text', 'len' => strlen($text), 'server' => $server, 'uniqueid' => $uniqueid]);
        return $text;
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

            Log::info('PBX_TRACE endpoint.json_shape', [
                'action' => $action,
                'keys' => array_slice(array_keys($json), 0, 20),
                'csv_count' => (isset($json['csv']) && is_array($json['csv'])) ? count($json['csv']) : 0,
                'header_count' => (isset($json['header']) && is_array($json['header'])) ? count($json['header']) : 0,
                'first_header_sample' => (isset($json['header']) && is_array($json['header'])) ? array_slice($json['header'], 0, 12) : [],
                'first_csv_row_sample' => (isset($json['csv'][0]) && is_array($json['csv'][0])) ? array_slice($json['csv'][0], 0, 12) : [],
            ]);

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

    /**
     * Fetch list of available tenants/servers from PBXware
     * Returns array keyed by server_id: { "2": { name, tenantcode, package, ... }, "3": { ... } }
     */
    public function fetchTenantList(): array
    {
        try {
            $response = $this->sendRequest('GET', 'pbxware.tenant.list', []);

            if ($response->failed()) {
                Log::error('PbxwareClient: tenant.list failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new PbxwareClientException("Tenant list request failed with status {$response->status()}");
            }

            $body = (string) $response->body();
            if (trim($body) === '') {
                Log::warning('PbxwareClient: tenant.list returned empty response');
                return [];
            }

            $contentType = strtolower((string) ($response->header('Content-Type') ?? ''));
            if (str_contains($contentType, 'json') || $this->looksLikeJson($body)) {
                $data = $response->json();
                Log::info('PbxwareClient: tenant.list success', [
                    'count' => is_array($data) ? count($data) : 0,
                ]);
                return is_array($data) ? $data : [];
            }

            Log::warning('PbxwareClient: tenant.list returned non-JSON response');
            return [];
        } catch (\Exception $e) {
            Log::error('PbxwareClient: tenant.list exception', [
                'message' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            throw new PbxwareClientException("Failed to fetch tenant list: {$e->getMessage()}", 0, $e);
        }
    }
}

