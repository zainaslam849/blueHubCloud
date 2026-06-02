<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Services\Admin\AdminCallPresenter;
use App\Services\Admin\AdminCallsIndexQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserCallsController extends Controller
{
    public function __construct(
        private readonly AdminCallPresenter $presenter,
    ) {}

    public function index(Request $request, AdminCallsIndexQueryService $queryService): JsonResponse
    {
        $user = Auth::guard('web')->user();

        if (! $user->company_id) {
            return response()->json([
                'data' => [],
                'meta' => ['currentPage' => 1, 'lastPage' => 1, 'perPage' => 25, 'total' => 0],
                'message' => 'No company assigned to your account yet.',
            ]);
        }

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'string', 'max:40'],
            'direction' => ['nullable', 'in:asc,desc'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        // Force-scope to the user's company
        $validated['company_id'] = $user->company_id;

        $result = $queryService->paginate($validated);
        $paginator = $result['paginator']->appends($request->query());

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (Call $call) => $this->presenter->toIndexRow($call))
                ->values(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
