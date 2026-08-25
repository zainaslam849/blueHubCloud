<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\PbxwareTenant;
use App\Services\Pbx\CountryTimezoneResolver;
use Illuminate\Console\Command;

/**
 * One-off fix for companies auto-created before country-based timezone
 * resolution existed (SyncPbxTenants used to hardcode 'UTC') — derives a
 * real timezone from the country_code already synced onto their linked
 * PBX tenant, so this doesn't have to be done company-by-company by hand.
 *
 * Only touches companies still sitting on the UTC/blank default; a company
 * an admin has already deliberately set is left untouched.
 */
class BackfillCompanyTimezones extends Command
{
    protected $signature = 'app:backfill-company-timezones {--dry-run : Show what would change without saving}';

    protected $description = 'Set a real timezone (from the synced PBX tenant country_code) on companies still stuck on UTC';

    public function handle(CountryTimezoneResolver $resolver): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $companies = Company::query()
            ->where(function ($q) {
                $q->whereNull('timezone')->orWhere('timezone', 'UTC')->orWhere('timezone', '');
            })
            ->with('companyPbxAccounts')
            ->get();

        if ($companies->isEmpty()) {
            $this->info('No companies are sitting on UTC/blank — nothing to do.');

            return self::SUCCESS;
        }

        $updated = 0;
        $skipped = 0;

        foreach ($companies as $company) {
            $account = $company->companyPbxAccounts->first();
            if (! $account) {
                $skipped++;
                continue;
            }

            $tenant = PbxwareTenant::query()
                ->where('pbx_provider_id', $account->pbx_provider_id)
                ->where('server_id', $account->server_id)
                ->first();

            $countryCode = $tenant?->country_code;
            if (! $countryCode) {
                $skipped++;
                continue;
            }

            // A sentinel fallback (rather than comparing against whatever the
            // configured default happens to be) unambiguously distinguishes
            // "genuinely resolved from this country code" from "resolver
            // couldn't do anything with it and fell through to the default".
            $timezone = $resolver->resolve($countryCode, '__UNRESOLVED__');
            if ($timezone === '__UNRESOLVED__') {
                $skipped++;
                continue;
            }

            $this->line("{$company->name} (#{$company->id}): {$company->timezone} -> {$timezone} (country: {$countryCode})");

            if (! $dryRun) {
                $company->update(['timezone' => $timezone]);
            }

            $updated++;
        }

        $this->newLine();
        $this->info(($dryRun ? '[dry run] ' : '')."Updated {$updated}, skipped {$skipped} (no linked tenant / no country code on file).");

        if ($skipped > 0) {
            $this->comment('Skipped companies still need a manual timezone set in Admin → Companies — no country data was available to infer one.');
        }

        return self::SUCCESS;
    }
}
