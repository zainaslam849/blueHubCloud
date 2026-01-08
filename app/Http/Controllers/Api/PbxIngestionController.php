<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Services\RecordingIngestionService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PbxIngestionController extends Controller
{
    public function store(Request $request, RecordingIngestionService $recordingIngestionService): JsonResponse
    {
        return $this->storeRecording($request, $recordingIngestionService);
    }

    public function storeRecording(Request $request, RecordingIngestionService $recordingIngestionService): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'pbx_provider_id' => ['required', 'integer', 'exists:pbx_providers,id'],
            'call_id' => ['required', 'integer', 'exists:calls,id'],

            'idempotency_key' => ['nullable', 'string', 'max:255'],

            'recording_url' => ['required', 'string', 'max:2048'],
            'recording_duration' => ['nullable', 'integer', 'min:0'],
            'storage_provider' => ['nullable', 'string', 'max:255'],

            'storage_path' => ['nullable', 'string', 'max:2048'],
            'file_size' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $call = Call::query()
                ->with('companyPbxAccount')
                ->find($validated['call_id']);

            if (! $call) {
                return response()->json([
                    'success' => false,
                    'message' => 'Call not found.',
                ], 404);
            }

            if ((int) $call->company_id !== (int) $validated['company_id']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Call does not belong to the given company.',
                ], 422);
            }

            $callProviderId = $call->companyPbxAccount?->pbx_provider_id;
            if (! $callProviderId || (int) $callProviderId !== (int) $validated['pbx_provider_id']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Call does not belong to the given PBX provider.',
                ], 422);
            }

            $recording = $recordingIngestionService->ingest($validated);

            return response()->json([
                'success' => true,
                'recording_id' => $recording->id,
                'status' => $recording->status,
            ], $recording->wasRecentlyCreated ? 201 : 200);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 409);
        } catch (Throwable $e) {
            Log::error('PBX recording ingestion failed.', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Server error while ingesting recording.',
            ], 500);
        }
    }
}
