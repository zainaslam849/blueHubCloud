<?php

namespace App\Http\Middleware;

use App\Models\CompanyPbxAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-API-KEY');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            return response()->json([
                'success' => false,
                'message' => 'Missing API key.',
            ], 401);
        }

        $account = CompanyPbxAccount::query()
            ->where('api_key', $apiKey)
            ->where('status', 'active')
            ->first();

        if (! $account) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        $request->attributes->set('company_pbx_account_id', $account->id);
        $request->attributes->set('company_id', $account->company_id);
        $request->attributes->set('pbx_provider_id', $account->pbx_provider_id);

        return $next($request);
    }
}
