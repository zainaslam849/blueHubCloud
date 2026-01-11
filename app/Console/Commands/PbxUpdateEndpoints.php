<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PbxUpdateEndpoints extends Command
{
    protected $signature = 'pbx:update-endpoints';
    protected $description = 'Update company_pbx_accounts.api_endpoint to PBXWARE_BASE_URL from .env';

    public function handle(): int
    {
        $url = env('PBXWARE_BASE_URL');
        if (empty($url)) {
            $this->error('PBXWARE_BASE_URL not set in environment');
            return 1;
        }

        $count = DB::table('company_pbx_accounts')->update(['api_endpoint' => $url]);
        $this->info("Updated api_endpoint for {$count} company_pbx_accounts to {$url}");
        return 0;
    }
}
