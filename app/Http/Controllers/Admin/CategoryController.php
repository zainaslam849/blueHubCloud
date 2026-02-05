<?php

namespace App\Http\Controllers\Admin;

use App\Models\CallCategory;
use App\Models\Company;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    /**
     * List all categories (including soft-deleted)
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->resolveCompanyId();

        $categories = CallCategory::withTrashed()
            ->where('company_id', $companyId)
            ->orderBy('is_enabled', 'desc')
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'data' => $categories,
            'meta' => [
                'total' => $categories->count(),
            ],
        ]);
    }

    /**
     * Get enabled categories only
     */
    public function enabled(Request $request): JsonResponse
    {
        $companyId = $this->resolveCompanyId();

        $categories = CallCategory::enabled()
            ->where('company_id', $companyId)
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'data' => $categories,
        ]);
    }

    /**
     * Create a new category
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:call_categories,name'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_enabled' => ['boolean'],
        ]);

        $validated['is_enabled'] = $validated['is_enabled'] ?? true;
        $validated['source'] = 'admin';
        $validated['status'] = 'active';
        $validated['company_id'] = $this->resolveCompanyId();

        $category = CallCategory::create($validated);

        return response()->json([
            'data' => $category,
            'message' => 'Category created successfully',
        ], 201);
    }

    /**
     * Get a single category
     */
    public function show(CallCategory $category): JsonResponse
    {
        return response()->json([
            'data' => $category,
        ]);
    }

    /**
     * Update a category
     */
    public function update(Request $request, CallCategory $category): JsonResponse
    {
        // Prevent editing the "General" category
        if ($category->isGeneral()) {
            throw ValidationException::withMessages([
                'name' => 'The "General" category cannot be edited.',
            ]);
        }

        // Prevent disabling the last enabled category
        if ($request->input('is_enabled') === false && $category->is_enabled) {
            if (CallCategory::countEnabled() === 1) {
                throw ValidationException::withMessages([
                    'is_enabled' => 'At least one category must remain enabled.',
                ]);
            }
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('call_categories', 'name')->ignore($category->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_enabled' => ['boolean'],
        ]);

        // Manual edits override AI-generated values
        $validated['source'] = 'admin';
        $validated['status'] = 'active';
        $validated['generated_by_model'] = null;

        $category->update($validated);

        return response()->json([
            'data' => $category,
            'message' => 'Category updated successfully',
        ]);
    }

    /**
     * Toggle category enabled/disabled status
     */
    public function toggle(CallCategory $category): JsonResponse
    {
        // Prevent toggling the "General" category
        if ($category->isGeneral()) {
            throw ValidationException::withMessages([
                'name' => 'The "General" category cannot be disabled.',
            ]);
        }

        // Prevent disabling if it's the last enabled category
        if ($category->is_enabled && CallCategory::countEnabled() === 1) {
            throw ValidationException::withMessages([
                'is_enabled' => 'At least one category must remain enabled.',
            ]);
        }

        $category->update([
            'is_enabled' => !$category->is_enabled,
        ]);

        return response()->json([
            'data' => $category,
            'message' => 'Category status updated successfully',
        ]);
    }

    /**
     * Soft delete a category
     */
    public function destroy(CallCategory $category): JsonResponse
    {
        // Prevent deleting the "General" category
        if ($category->isGeneral()) {
            throw ValidationException::withMessages([
                'name' => 'The "General" category cannot be deleted.',
            ]);
        }

        $category->delete();

        return response()->json([
            'message' => 'Category deleted successfully',
        ]);
    }

    /**
     * Restore a soft-deleted category
     */
    public function restore(int $id): JsonResponse
    {
        $category = CallCategory::onlyTrashed()->findOrFail($id);

        $category->restore();

        return response()->json([
            'data' => $category,
            'message' => 'Category restored successfully',
        ]);
    }

    /**
     * Permanently delete a category
     */
    public function forceDelete(int $id): JsonResponse
    {
        $category = CallCategory::withTrashed()->findOrFail($id);

        // Prevent force-deleting the "General" category
        if ($category->isGeneral()) {
            throw ValidationException::withMessages([
                'name' => 'The "General" category cannot be permanently deleted.',
            ]);
        }

        $category->forceDelete();

        return response()->json([
            'message' => 'Category permanently deleted successfully',
        ]);
    }

    private function resolveCompanyId(): int
    {
        return (int) (Company::orderBy('id')->value('id') ?? 1);
    }
}
