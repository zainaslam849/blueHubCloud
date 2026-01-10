<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\IngestPbxCallsJob;
use App\Models\CompanyPbxAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PbxIngestController extends Controller
{
    public function trigger(Request $request)
    {
        Log::info('Admin triggered PBX ingest via controller', ['user_id' => optional($request->user())->id ?? null]);

        $accounts = CompanyPbxAccount::where('status', 'active')->get();

        $dispatched = 0;
        foreach ($accounts as $account) {
            IngestPbxCallsJob::dispatch($account->company_id, $account->id)->onQueue('ingest-pbx');
            $dispatched++;
        }

        Log::info('Admin PBX ingest dispatch complete', ['dispatched' => $dispatched]);

        return response()->json([
            'status' => 'ok',
            'dispatched' => $dispatched,
        ]);
    }
}
