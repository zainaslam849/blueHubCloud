<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Repositories\AiSettingsRepository;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

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

        return response()->json(['data' => $active]);
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

        $data = [
            'provider' => $validated['provider'],
            'api_key' => $validated['api_key'] ?? null,
            'categorization_model' => $validated['categorization_model'],
            'categorization_system_prompt' => $validated['categorization_system_prompt'] ?? null,
            'summary_system_prompt' => $validated['summary_system_prompt'] ?? null,
            'report_model' => $validated['report_model'],
            'enabled' => ! empty($validated['enabled']),
        ];

        // If an active config exists, update it; otherwise create a new one.
        $active = $this->repo->getActive();
        if ($active) {
            $model = $this->repo->update($active->id, $data);
            $status = 200;
        } else {
            $model = $this->repo->create($data);
            $status = 201;
        }

        // Do not return api_key
        $model->makeHidden(['api_key']);

        return response()->json(['data' => $model], $status);
    }
}
