<?php

namespace App\Services\Admin;

use App\Models\Call;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AdminCallsIndexQueryService
{
    /**
     * Build the filtered/sorted calls index query and return a paginator.
     * The controller remains responsible for response shape so the HTTP
     * payload contract stays unchanged while query orchestration moves out.
     *
     * @param array<string,mixed> $validated
     * @return array{paginator: LengthAwarePaginator, sort: string, direction: string}
     */
    public function paginate(array $validated): array
    {
        $perPage = (int) ($validated['per_page'] ?? 25);
        $search = trim((string) ($validated['search'] ?? ''));

        $sort = (string) ($validated['sort'] ?? 'created_at');
        $direction = (string) ($validated['direction'] ?? 'desc');

        $allowedSort = [
            'id',
            'pbx_unique_id',
            'duration_seconds',
            'status',
            'created_at',
            'started_at',
            'company',
            'provider',
        ];

        if (! in_array($sort, $allowedSort, true)) {
            $sort = 'created_at';
        }

        $query = Call::query()->select('calls.*')
            ->with([
                'company:id,name',
                'companyPbxAccount:id,pbx_provider_id,company_id',
                'companyPbxAccount.pbxProvider:id,name',
                'category:id,name',
                'subCategory:id,name',
            ]);

        $needsCompanyJoin = $sort === 'company' || $search !== '';
        $needsProviderJoin = $sort === 'provider';

        if ($needsCompanyJoin) {
            $query->leftJoin('companies', 'companies.id', '=', 'calls.company_id');
        }

        if ($needsProviderJoin) {
            $query->leftJoin('company_pbx_accounts', 'company_pbx_accounts.id', '=', 'calls.company_pbx_account_id');
            $query->leftJoin('pbx_providers', 'pbx_providers.id', '=', 'company_pbx_accounts.pbx_provider_id');
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $numericId = ctype_digit($search) ? (int) $search : null;

                if ($numericId) {
                    $q->orWhere('calls.id', $numericId);
                }

                $q->orWhere('calls.pbx_unique_id', 'like', "%{$search}%")
                    ->orWhere('calls.status', 'like', "%{$search}%")
                    ->orWhere('calls.direction', 'like', "%{$search}%")
                    ->orWhere('calls.from', 'like', "%{$search}%")
                    ->orWhere('calls.to', 'like', "%{$search}%")
                    ->orWhere('companies.name', 'like', "%{$search}%");
            });
        }

        if (isset($validated['company_id'])) {
            $query->where('calls.company_id', $validated['company_id']);
        }

        if (isset($validated['category_id'])) {
            $query->where('calls.category_id', $validated['category_id']);
        }

        if (isset($validated['source'])) {
            $query->where('calls.category_source', $validated['source']);
        }

        if (isset($validated['confidence_min'])) {
            $query->where('calls.category_confidence', '>=', $validated['confidence_min']);
        }

        if (isset($validated['confidence_max'])) {
            $query->where('calls.category_confidence', '<=', $validated['confidence_max']);
        }

        if (isset($validated['start_date'])) {
            $startDate = Carbon::parse($validated['start_date'])->startOfDay();
            $query->where('calls.created_at', '>=', $startDate);
        }

        if (isset($validated['end_date'])) {
            $endDate = Carbon::parse($validated['end_date'])->endOfDay();
            $query->where('calls.created_at', '<=', $endDate);
        }

        if ($sort === 'company') {
            $query->orderBy('companies.name', $direction);
        } elseif ($sort === 'provider') {
            $query->orderBy('pbx_providers.name', $direction);
        } else {
            $query->orderBy("calls.{$sort}", $direction);
        }

        $query->orderBy('calls.id', 'desc');

        return [
            'paginator' => $query->paginate($perPage),
            'sort' => $sort,
            'direction' => $direction,
        ];
    }
}
