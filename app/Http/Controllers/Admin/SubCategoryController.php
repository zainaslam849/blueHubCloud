<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CallCategory;
use App\Models\SubCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubCategoryController extends Controller
{
    /**
     * Get all sub-categories for a category
     */
    public function index($categoryId)
    {
        $category = CallCategory::findOrFail($categoryId);
        $company = $this->resolveAuthenticatedCompany();
        if ($category->company_id !== $company->id) {
            abort(403, 'Category does not belong to your company.');
        }

        $subCategories = $category->subCategories()
            ->withTrashed()
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $subCategories,
        ]);
    }

    /**
     * Store a new sub-category
     */
    public function store(Request $request, $categoryId)
    {
        $category = CallCategory::findOrFail($categoryId);
        $company = $this->resolveAuthenticatedCompany();
        if ($category->company_id !== $company->id) {
            abort(403, 'Category does not belong to your company.');
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('sub_categories', 'name')->where('category_id', $category->id),
            ],
            'description' => 'nullable|string|max:1000',
            'is_enabled' => 'boolean',
        ]);

        $validated['source'] = 'admin';
        $validated['status'] = 'active';

        $subCategory = $category->subCategories()->create($validated);

        return response()->json([
            'data' => $subCategory,
            'message' => 'Sub-category created successfully',
        ], 201);
    }

    /**
     * Update a sub-category
     */
    public function update(Request $request, $categoryId, $subCategoryId)
    {
        $category = CallCategory::findOrFail($categoryId);
        $company = $this->resolveAuthenticatedCompany();
        if ($category->company_id !== $company->id) {
            abort(403, 'Category does not belong to your company.');
        }
        $subCategory = $category->subCategories()->withTrashed()->findOrFail($subCategoryId);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('sub_categories', 'name')
                    ->where('category_id', $category->id)
                    ->ignore($subCategoryId),
            ],
            'description' => 'nullable|string|max:1000',
            'is_enabled' => 'boolean',
        ]);

        // Manual edits override AI-generated values
        $validated['source'] = 'admin';
        $validated['status'] = 'active';

        $subCategory->update($validated);

        return response()->json([
            'data' => $subCategory,
            'message' => 'Sub-category updated successfully',
        ]);
    }

    /**
     * Toggle sub-category enabled/disabled
     */
    public function toggle($categoryId, $subCategoryId)
    {
        $category = CallCategory::findOrFail($categoryId);
        $company = $this->resolveAuthenticatedCompany();
        if ($category->company_id !== $company->id) {
            abort(403, 'Category does not belong to your company.');
        }
        $subCategory = $category->subCategories()->findOrFail($subCategoryId);

        $subCategory->update([
            'is_enabled' => !$subCategory->is_enabled,
        ]);

        return response()->json([
            'data' => $subCategory,
            'message' => $subCategory->is_enabled ? 'Sub-category enabled' : 'Sub-category disabled',
        ]);
    }

    /**
     * Soft delete a sub-category
     */
    public function destroy($categoryId, $subCategoryId)
    {
        $category = CallCategory::findOrFail($categoryId);
        $company = $this->resolveAuthenticatedCompany();
        if ($category->company_id !== $company->id) {
            abort(403, 'Category does not belong to your company.');
        }
        $subCategory = $category->subCategories()->findOrFail($subCategoryId);

        $subCategory->delete();

        return response()->json([
            'message' => 'Sub-category deleted successfully',
        ]);
    }

    /**
     * Restore a soft-deleted sub-category
     */
    public function restore($categoryId, $subCategoryId)
    {
        $category = CallCategory::findOrFail($categoryId);
        $company = $this->resolveAuthenticatedCompany();
        if ($category->company_id !== $company->id) {
            abort(403, 'Category does not belong to your company.');
        }
        $subCategory = $category->subCategories()->withTrashed()->findOrFail($subCategoryId);

        if (!$subCategory->deleted_at) {
            return response()->json([
                'message' => 'Sub-category is not deleted',
            ], 400);
        }

        $subCategory->restore();

        return response()->json([
            'data' => $subCategory,
            'message' => 'Sub-category restored successfully',
        ]);
    }

    /**
     * Force delete a sub-category
     */
    public function forceDelete($categoryId, $subCategoryId)
    {
        $category = CallCategory::findOrFail($categoryId);
        $company = $this->resolveAuthenticatedCompany();
        if ($category->company_id !== $company->id) {
            abort(403, 'Category does not belong to your company.');
        }
        $subCategory = $category->subCategories()->withTrashed()->findOrFail($subCategoryId);

        $subCategory->forceDelete();

        return response()->json([
            'message' => 'Sub-category permanently deleted',
        ]);
    }
}
