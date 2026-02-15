<?php

namespace App\Http\Controllers\Admin;

use App\Models\CallCategory;
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
        $validated = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'status' => ['nullable', 'in:active,archived'],
            'source' => ['nullable', 'in:ai,admin'],
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $perPage = $validated['per_page'] ?? 25;

        $query = CallCategory::withTrashed()
            ->with('company:id,name')
            ->when($validated['company_id'] ?? null, function ($query, $companyId) {
                // Filter by specific company if provided
                $query->where('company_id', $companyId);
            })
            ->when($validated['status'] ?? null, function ($query, $status) {
                $query->where('status', $status);
            })
            ->when($validated['source'] ?? null, function ($query, $source) {
                $query->where('source', $source);
            })
            ->when($validated['search'] ?? null, function ($query, $search) {
                $query->where('name', 'like', '%'.$search.'%');
            })
            ->withCount(['subCategories' => function ($query) {
                $query->withTrashed();
            }])
            ->orderBy('is_enabled', 'desc')
            ->orderBy('name', 'asc');

        $categories = $query->paginate($perPage);

        return response()->json([
            'data' => $categories->items(),
            'meta' => [
                'current_page' => $categories->currentPage(),
                'last_page' => $categories->lastPage(),
                'per_page' => $categories->perPage(),
                'total' => $categories->total(),
                'from' => $categories->firstItem(),
                'to' => $categories->lastItem(),
            ],
        ]);
    }

    /**
     * Get enabled categories only
     */
    public function enabled(Request $request): JsonResponse
    {
        $company = $this->resolveAuthenticatedCompany();

        $categories = CallCategory::enabled()
            ->where('company_id', $company->id)
            ->where('status', 'active')
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
        $company = $this->resolveAuthenticatedCompany();

        $validated = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_enabled' => ['boolean'],
        ]);

        // Use provided company_id or fall back to authenticated user's company
        $targetCompanyId = $validated['company_id'] ?? $company->id;

        // Check unique constraint for the target company
        $existingCategory = CallCategory::where('company_id', $targetCompanyId)
            ->where('name', $validated['name'])
            ->exists();

        if ($existingCategory) {
            throw ValidationException::withMessages([
                'name' => 'A category with this name already exists for this company.',
            ]);
        }

        $validated['is_enabled'] = $validated['is_enabled'] ?? true;
        $validated['source'] = 'admin';
        $validated['status'] = 'active';
        $validated['company_id'] = $targetCompanyId;

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
        $willDisable = $request->input('is_enabled') === false
            || $request->input('status') === 'archived';

        if ($willDisable && $category->is_enabled) {
            $enabledCount = CallCategory::query()
                ->where('company_id', $company->id)
                ->enabled()
                ->count();
            if ($enabledCount === 1) {
                throw ValidationException::withMessages([
                    'is_enabled' => 'At least one category must remain enabled.',
                ]);
            }
        }

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('call_categories', 'name')
                    ->where('company_id', $company->id)
                    ->ignore($category->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_enabled' => ['boolean'],
            'status' => ['nullable', Rule::in(['active', 'archived'])],
        ]);

        if (array_key_exists('status', $validated)) {
            if ($validated['status'] === 'archived') {
                $validated['is_enabled'] = false;
            }
            if ($validated['status'] === 'active' && ! array_key_exists('is_enabled', $validated)) {
                $validated['is_enabled'] = true;
            }
        }

        $manualEdit = $request->hasAny(['name', 'description']);
        if ($manualEdit) {
            $validated['source'] = 'admin';
            $validated['generated_by_model'] = null;
            if (! array_key_exists('status', $validated)) {
                $validated['status'] = 'active';
            }
        }

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
        $company = $this->resolveAuthenticatedCompany();
        if ($category->company_id !== $company->id) {
            abort(403, 'Category does not belong to your company.');
        }

        // Prevent toggling the "General" category
        if ($category->isGeneral()) {
            throw ValidationException::withMessages([
                'name' => 'The "General" category cannot be disabled.',
            ]);
        }

        // Prevent disabling if it's the last enabled category
        $enabledCount = CallCategory::query()
            ->where('company_id', $company->id)
            ->enabled()
            ->count();

        if ($category->is_enabled && $enabledCount === 1) {
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

}
