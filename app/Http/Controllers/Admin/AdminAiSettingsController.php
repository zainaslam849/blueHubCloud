<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Repositories\AiSettingsRepository;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class AdminAiSettingsController extends Controller
{
    private AiSettingsRepository $repo;

    public function __construct(AiSettingsRepository $repo)
    {
        $this->repo = $repo;
    }

    public function index(): JsonResponse
    {
        $active = $this->repo->getActive();
        
        if ($active) {
            $active = $this->maskApiKey($active);
        }

        return response()->json(['data' => $active]);
    }
    
    public function test(): JsonResponse
    {
        $active = $this->repo->getActive();
        
        if (!$active || !$active->enabled) {
            return response()->json([
                'success' => false,
                'message' => 'AI integration not enabled'
            ], 400);
        }
        
        if (!$active->api_key) {
            return response()->json([
                'success' => false,
                'message' => 'API key not configured'
            ], 400);
        }
        
        try {
            // Test API call to verify connectivity
            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $active->api_key,
                    'HTTP-Referer' => config('app.url'),
                ])
                ->post('https://openrouter.ai/api/v1/auth/key', [])
                ->throw();
            
            return response()->json([
                'success' => true,
                'message' => 'AI connection verified',
                'data' => [
                    'provider' => $active->provider,
                    'models' => [
                        'categorization' => $active->categorization_model,
                        'reports' => $active->report_model,
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to connect to AI service: ' . $e->getMessage()
            ], 400);
        }
    }
    
    private function maskApiKey($setting)
    {
        if (!$setting->api_key) {
            $setting->api_key_hint = null;
            return $setting;
        }
        
        $key = $setting->api_key;
        $length = strlen($key);
        
        if ($length <= 8) {
            $setting->api_key_hint = str_repeat('*', $length);
        } else {
            $start = substr($key, 0, 4);
            $end = substr($key, -4);
            $middle = str_repeat('•', $length - 8);
            $setting->api_key_hint = $start . $middle . $end;
        }
        
        // Never return the actual key
        $setting->makeHidden(['api_key']);
        return $setting;
    }

    public function store(Request $request): JsonResponse
    {
        $allowed = [
            'openai/gpt-4o-mini',
            'openai/gpt-4.1-mini',
            'openai/gpt-5.2',
            'anthropic/claude-3.5-sonnet',
            'google/gemini-1.5-flash',
            'google/gemini-1.5-pro',
        ];

        $validated = $request->validate([
            'provider' => ['required', 'string'],
            'api_key' => ['nullable', 'string'],
            'categorization_model' => ['required', 'string', 'in:' . implode(',', $allowed)],
            'categorization_system_prompt' => ['nullable', 'string', 'max:10000'],
            'summary_system_prompt' => ['nullable', 'string', 'max:10000'],
            'report_model' => ['required', 'string', 'in:' . implode(',', $allowed)],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        if ($validated['categorization_model'] === $validated['report_model']) {
            return response()->json(['message' => 'Categorization and report models must differ.'], 422);
        }

        $active = $this->repo->getActive();
        
        $data = [
            'provider' => $validated['provider'],
            'categorization_model' => $validated['categorization_model'],
            'categorization_system_prompt' => $validated['categorization_system_prompt'] ?? null,
            'summary_system_prompt' => $validated['summary_system_prompt'] ?? null,
            'report_model' => $validated['report_model'],
            'enabled' => ! empty($validated['enabled']),
        ];
        
        // Only update api_key if provided (non-empty)
        if (!empty($validated['api_key'])) {
            $data['api_key'] = $validated['api_key'];
        } elseif ($active) {
            // Keep existing key if not updating
            $data['api_key'] = $active->api_key;
        }

        // If an active config exists, update it; otherwise create a new one.
        if ($active) {
            $model = $this->repo->update($active->id, $data);
            $status = 200;
        } else {
            $model = $this->repo->create($data);
            $status = 201;
        }

        $model = $this->maskApiKey($model);

        return response()->json(['data' => $model], $status);
    }
}
