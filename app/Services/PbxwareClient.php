<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Stream;
use Illuminate\Support\Facades\Log;
use Aws\SecretsManager\SecretsManagerClient;
use Aws\Exception\AwsException;
use Psr\Http\Message\StreamInterface;
use App\Exceptions\PbxwareClientException;

use Psr\Http\Message\ResponseInterface;

class PbxwareClient
{
    protected $client;
    protected $baseUrl;
    protected $credentials;

    public function __construct(?string $baseUrl = null)
    {
        $this->baseUrl = $baseUrl ?? config('services.pbxware.base_url');
        $this->credentials = $this->getCredentials();
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout'  => 30.0,
        ]);
    }

    protected function getCredentials(): array
    {
        try {
            $client = new SecretsManagerClient([
                'version' => 'latest',
                'region'  => config('services.pbxware.aws_region', 'ap-southeast-2'),
            ]);
            $result = $client->getSecretValue([
                'SecretId' => 'pbxware/api-credentials',
            ]);
            $secret = $result['SecretString'] ?? '';
            return json_decode($secret, true);
        } catch (AwsException $e) {
            Log::error('Failed to load PBXware credentials from Secrets Manager', [
                'error' => $e->getMessage(),
            ]);
            throw new PbxwareClientException('Could not load PBXware credentials');
        }
    }

    protected function getAuthHeaders(): array
    {
        // Example: Basic Auth, adapt as needed for PBXware
        if (isset($this->credentials['username'], $this->credentials['password'])) {
            $token = base64_encode($this->credentials['username'] . ':' . $this->credentials['password']);
            return [
                'Authorization' => 'Basic ' . $token,
            ];
        }
        throw new PbxwareClientException('PBXware credentials are incomplete');
    }

    public function fetchCalls(array $params = [], array $context = []): array
    {
        return $this->paginate('/calls', $params, $context);
    }

    public function fetchRecordings(array $params = [], array $context = []): array
    {
        return $this->paginate('/recordings', $params, $context);
    }

    public function downloadRecordingStream(string $recordingId, array $context = []): StreamInterface
    {
        try {
            $response = $this->sendRequest('GET', "/recordings/{$recordingId}/download", [
                'headers' => $this->getAuthHeaders(),
                'stream' => true,
            ], $context);

            if ($response->getStatusCode() !== 200) {
                Log::error('PBXware downloadRecordingStream non-200', array_merge($context, ['status' => $response->getStatusCode(), 'recording_id' => $recordingId]));
                throw new PbxwareClientException('Failed to download recording stream');
            }

            return $response->getBody();
        } catch (RequestException $e) {
            Log::error('PBXware downloadRecordingStream failed', array_merge($context, [
                'recording_id' => $recordingId,
                'error' => $e->getMessage(),
            ]));
            throw new PbxwareClientException('Failed to download recording stream');
        }
    }

    protected function paginate(string $endpoint, array $params = [], array $context = []): array
    {
        $results = [];
        $page = 1;
        $perPage = $params['per_page'] ?? 100;
        do {
            $query = array_merge($params, [
                'page' => $page,
                'per_page' => $perPage,
            ]);
            try {
                $response = $this->sendRequest('GET', $endpoint, [
                    'headers' => $this->getAuthHeaders(),
                    'query' => $query,
                ], $context);

                if ($response->getStatusCode() !== 200) {
                    Log::error('PBXware paginate non-200', array_merge($context, ['endpoint' => $endpoint, 'status' => $response->getStatusCode(), 'query' => $this->redactForLog($query)]));
                    throw new PbxwareClientException("Non-200 response: {$response->getStatusCode()}");
                }

                $data = json_decode($response->getBody()->getContents(), true);
                $items = $data['data'] ?? [];
                $results = array_merge($results, $items);
                $total = $data['total'] ?? null;
                $count = count($items);
                $page++;
            } catch (RequestException $e) {
                Log::error('PBXware API pagination failed', array_merge($context, [
                    'endpoint' => $endpoint,
                    'params' => $this->redactForLog($params),
                    'error' => $e->getMessage(),
                ]));
                throw new PbxwareClientException('Failed to fetch paginated data');
            }
        } while ($count === $perPage && ($total === null || count($results) < $total));
        return $results;
    }

    /**
     * Send a request and log latency. Redacts sensitive fields from logs.
     *
     * @param string $method
     * @param string $uri
     * @param array $options
     * @param array $context
     * @return ResponseInterface
     */
    protected function sendRequest(string $method, string $uri, array $options = [], array $context = []): ResponseInterface
    {
        $start = microtime(true);
        try {
            $response = $this->client->request($method, $uri, $options);
            $latency = round((microtime(true) - $start) * 1000, 2);

            Log::info('PBXware API request', array_merge($context, [
                'method' => $method,
                'uri' => $uri,
                'status' => $response->getStatusCode(),
                'latency_ms' => $latency,
            ]));

            return $response;
        } catch (RequestException $e) {
            $latency = round((microtime(true) - $start) * 1000, 2);
            Log::error('PBXware API request failed', array_merge($context, [
                'method' => $method,
                'uri' => $uri,
                'latency_ms' => $latency,
                'error' => $e->getMessage(),
                'request' => $this->redactForLog($options),
            ]));
            throw $e;
        }
    }

    /**
     * Redact sensitive keys from arrays for logging.
     */
    protected function redactForLog(array $data): array
    {
        $sensitive = ['password', 'api_key', 'api_secret', 'secret', 'token', 'authorization', 'Authorization'];

        $copy = $data;

        array_walk_recursive($copy, function (&$value, $key) use ($sensitive) {
            if (in_array($key, $sensitive, true)) {
                $value = 'REDACTED';
            }
        });

        // Redact Authorization header if present
        if (isset($copy['headers']['Authorization'])) {
            $copy['headers']['Authorization'] = 'REDACTED';
        }

        return $copy;
    }
}
