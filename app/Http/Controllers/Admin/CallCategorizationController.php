<?php

namespace App\Http\Controllers\Admin;

use App\Models\CallCategory;
use App\Services\CallCategorizationPromptService;
use App\Services\CallCategorizationPersistenceService;
use Illuminate\Routing\Controller;

class CallCategorizationController extends Controller
{
    /**
     * Get the AI categorization prompt for a call
     * Used by AI services to categorize calls
     */
    public function generatePrompt()
    {
        return response()->json([
            'system_prompt' => CallCategorizationPromptService::getSystemPrompt(),
            'model_parameters' => CallCategorizationPromptService::getModelParameters(),
            'categories' => $this->getEnabledCategoriesWithSubcategories(),
        ]);
    }

    /**
     * Get enabled categories for display/selection
     */
    public function getEnabledCategories()
    {
        $categories = CallCategory::enabled()
            ->with(['subCategories' => function ($query) {
                $query->enabled();
            }])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $categories->map(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'description' => $category->description,
                    'sub_categories' => $category->subCategories->map(function ($sub) {
                        return [
                            'id' => $sub->id,
                            'name' => $sub->name,
                            'description' => $sub->description,
                        ];
                    })->values(),
                ];
            })->values(),
        ]);
    }

    /**
     * Validate AI categorization response
     */
    public function validateCategorization()
    {
        $validated = request()->validate([
            'category' => 'required|string',
            'sub_category' => 'nullable|string',
            'confidence' => 'required|numeric|between:0,1',
        ]);

        $result = CallCategorizationPromptService::validateCategorization($validated);

        return response()->json($result, $result['valid'] ? 200 : 400);
    }

    /**
     * Get full prompt for a specific call
     */
    public function buildCallPrompt()
    {
        $validated = request()->validate([
            'transcript' => 'required|string',
            'direction' => 'nullable|in:inbound,outbound',
            'status' => 'nullable|in:completed,missed,failed',
            'duration' => 'nullable|integer|min:0',
            'is_after_hours' => 'nullable|boolean',
        ]);

        $prompt = CallCategorizationPromptService::buildPromptObject(
            $validated['transcript'],
            $validated['direction'] ?? 'inbound',
            $validated['status'] ?? 'completed',
            $validated['duration'] ?? 0,
            $validated['is_after_hours'] ?? false
        );

        return response()->json([
            'data' => $prompt,
        ]);
    }

    /**
     * Helper to get enabled categories with sub-categories
     */
    private function getEnabledCategoriesWithSubcategories()
    {
        return CallCategory::enabled()
            ->with(['subCategories' => function ($query) {
                $query->enabled();
            }])
            ->orderBy('name')
            ->get()
            ->map(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'description' => $category->description,
                    'sub_categories' => $category->subCategories->map(function ($sub) {
                        return [
                            'id' => $sub->id,
                            'name' => $sub->name,
                            'description' => $sub->description,
                        ];
                    })->values()->toArray(),
                ];
            })
            ->values();
    }

    /**
     * Persist AI categorization result to database
     * 
     * Validation rules:
     * - If category not found → fallback to "General"
     * - If confidence < 0.4 → mark as "Other"
     * - Sub-category stored as label if not found
     */
    public function persistCategorization()
    {
        $validated = request()->validate([
            'call_id' => 'required|integer|exists:calls,id',
            'category' => 'required|string',
            'sub_category' => 'nullable|string',
            'confidence' => 'required|numeric|between:0,1',
        ]);

        try {
            $result = CallCategorizationPersistenceService::persistCategorization(
                $validated['call_id'],
                $validated['category'],
                $validated['sub_category'] ?? null,
                $validated['confidence']
            );

            return response()->json($result);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk persist multiple categorizations
     */
    public function bulkPersistCategorizations()
    {
        $validated = request()->validate([
            'categorizations' => 'required|array',
            'categorizations.*.call_id' => 'required|integer|exists:calls,id',
            'categorizations.*.category' => 'required|string',
            'categorizations.*.sub_category' => 'nullable|string',
            'categorizations.*.confidence' => 'required|numeric|between:0,1',
        ]);

        $result = CallCategorizationPersistenceService::bulkPersist($validated['categorizations']);

        return response()->json($result);
    }
}
