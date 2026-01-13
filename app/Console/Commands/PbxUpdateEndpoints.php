<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PbxUpdateEndpoints extends Command
{
    protected $signature = 'pbx:update-endpoints';
    protected $description = 'Deprecated: PBX base URL is centralized in AWS Secrets Manager';

    public function handle(): int
    {
        $this->error('This command is deprecated. PBX base URL is intentionally centralized in the "pbxware/api-credentials" AWS Secrets Manager secret.');
        $this->line('No database updates were performed.');
        return 1;
    }
}
