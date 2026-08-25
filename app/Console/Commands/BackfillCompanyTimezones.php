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
 *
 * --fallback=<timezone> also sets companies where the PBX country_code
 * couldn't be resolved (missing/unrecognized) to a given timezone — useful
 * when the whole client base is known to be in one country regardless of
 * whether the PBX API is reliably supplying country_code.
 */
class BackfillCompanyTimezones extends Command
{
    protected $signature = 'app:backfill-company-timezones
        {--dry-run : Show what would change without saving}
        {--fallback= : Set companies to this timezone if the PBX country_code can\'t resolve one (e.g. --fallback=Australia/Sydney). Without this, those are left alone and just reported.}';

    protected $description = 'Set a real timezone (from the synced PBX tenant country_code) on companies still stuck on UTC';

    public function handle(CountryTimezoneResolver $resolver): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $fallbackTimezone = $this->option('fallback');

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
            $tenant = $account
                ? PbxwareTenant::query()
                    ->where('pbx_provider_id', $account->pbx_provider_id)
                    ->where('server_id', $account->server_id)
                    ->first()
                : null;

            $countryCode = $tenant?->country_code;

            // A sentinel fallback (rather than comparing against whatever the
            // configured default happens to be) unambiguously distinguishes
            // "genuinely resolved from this country code" from "resolver
            // couldn't do anything with it and fell through to the default".
            $timezone = $countryCode ? $resolver->resolve($countryCode, '__UNRESOLVED__') : '__UNRESOLVED__';

            if ($timezone === '__UNRESOLVED__') {
                if ($fallbackTimezone) {
                    $timezone = $fallbackTimezone;
                    $this->line("{$company->name} (#{$company->id}): {$company->timezone} -> {$timezone} (no country data — used --fallback)");
                } else {
                    $skipped++;
                    continue;
                }
            } else {
                $this->line("{$company->name} (#{$company->id}): {$company->timezone} -> {$timezone} (country: {$countryCode})");
            }

            if (! $dryRun) {
                $company->update(['timezone' => $timezone]);
            }

            $updated++;
        }

        $this->newLine();
        $this->info(($dryRun ? '[dry run] ' : '')."Updated {$updated}, skipped {$skipped}.");

        if ($skipped > 0) {
            $this->comment($fallbackTimezone
                ? 'Skipped companies have no linked PBX account at all — nothing to base even a fallback on.'
                : 'Skipped companies have no country data to infer from. Re-run with --fallback=Australia/Sydney to set those too, or fix them individually in Admin → Companies.');
        }

        return self::SUCCESS;
    }
}
