<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Centralized JSON response helper for HTTP API endpoints.
 *
 * The primary purpose is to keep server-side details (raw exception messages,
 * stack frames, internal state) out of HTTP responses while still emitting a
 * stable correlation_id that operators can grep in the logs. Controllers
 * should call ApiResponse::error() in catch blocks rather than echoing
 * $e->getMessage() directly to clients.
 */
class ApiResponse
{
    /**
     * Build a sanitized error JSON response.
     *
     * @param  int          $status        HTTP status code (e.g. 400, 403, 500).
     * @param  string       $message       Safe, user-facing message. Do NOT pass raw exception text.
     * @param  string|null  $correlationId Optional pre-existing correlation id; one is generated if null.
     * @param  array        $extra         Optional safe context (e.g. validation hints). Avoid sensitive data.
     */
    public static function error(int $status, string $message, ?string $correlationId = null, array $extra = []): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
            'correlation_id' => $correlationId ?: (string) Str::uuid(),
        ];

        if (! empty($extra)) {
            $payload = array_merge($payload, $extra);
        }

        return response()->json($payload, $status);
    }

    /**
     * Build a success JSON response with a stable envelope.
     *
     * @param  mixed                 $data
     * @param  string|null           $message
     * @param  int                   $status
     */
    public static function success($data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        $payload = ['success' => true];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        if ($data !== null) {
            $payload['data'] = $data;
        }

        return response()->json($payload, $status);
    }
}
