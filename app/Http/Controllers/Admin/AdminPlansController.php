<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPlansController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = Plan::orderBy('name')->get();
        return response()->json(['data' => $plans->map(fn ($p) => $this->format($p))]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'         => ['required', 'string', 'max:255'],
            'minute_limit' => ['required', 'integer', 'min:1'],
            'price'        => ['required', 'numeric', 'min:0'],
            'sale_price'   => ['nullable', 'numeric', 'min:0'],
            'is_active'    => ['sometimes', 'boolean'],
        ]);

        // Null out sale_price if it's empty string or equals the regular price
        if (isset($validated['sale_price']) && $validated['sale_price'] === '') {
            $validated['sale_price'] = null;
        }

        $plan = Plan::create($validated);

        return response()->json(['data' => $this->format($plan), 'message' => 'Plan created successfully.'], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->format(Plan::findOrFail($id))]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $plan = Plan::findOrFail($id);

        $validated = $request->validate([
            'name'         => ['sometimes', 'string', 'max:255'],
            'minute_limit' => ['sometimes', 'integer', 'min:1'],
            'price'        => ['sometimes', 'numeric', 'min:0'],
            'sale_price'   => ['nullable', 'numeric', 'min:0'],
            'is_active'    => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('sale_price', $validated) && $validated['sale_price'] === '') {
            $validated['sale_price'] = null;
        }

        $plan->update($validated);

        return response()->json(['data' => $this->format($plan), 'message' => 'Plan updated successfully.']);
    }

    public function destroy(int $id): JsonResponse
    {
        $plan = Plan::findOrFail($id);
        $plan->update(['is_active' => false]);

        return response()->json(['message' => 'Plan deactivated successfully.']);
    }

    private function format(Plan $plan): array
    {
        return [
            'id'               => $plan->id,
            'name'             => $plan->name,
            'minute_limit'     => $plan->minute_limit,
            'price'            => $plan->price,
            'sale_price'       => $plan->sale_price,
            'has_sale'         => $plan->has_sale,
            'discount_percent' => $plan->discount_percent,
            'effective_price'  => $plan->effective_price,
            'is_active'        => $plan->is_active,
            'created_at'       => $plan->created_at?->toIso8601String(),
        ];
    }
}
