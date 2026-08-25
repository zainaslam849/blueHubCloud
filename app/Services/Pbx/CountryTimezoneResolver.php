<?php

namespace App\Services\Pbx;

/**
 * Resolves a sensible default IANA timezone from a PBX tenant's ISO
 * country_code (already synced from the PBXware API into
 * pbxware_tenants.country_code) — so a new company gets a real timezone
 * automatically instead of silently defaulting to UTC.
 *
 * For countries with a single zone, PHP's built-in per-country timezone
 * database is authoritative. For large multi-zone countries it isn't
 * ordered by relevance, so a short list of representative overrides
 * (covering this client base) takes priority.
 */
class CountryTimezoneResolver
{
    /**
     * Representative timezone for multi-zone countries where PHP's
     * unordered per-country list would otherwise pick an unlikely zone
     * (e.g. Australia's list starts alphabetically, not by population).
     *
     * @var array<string,string>
     */
    private const REPRESENTATIVE = [
        'AU' => 'Australia/Sydney',
        'US' => 'America/New_York',
        'CA' => 'America/Toronto',
        'GB' => 'Europe/London',
        'NZ' => 'Pacific/Auckland',
        'IN' => 'Asia/Kolkata',
        'BR' => 'America/Sao_Paulo',
        'RU' => 'Europe/Moscow',
        'CN' => 'Asia/Shanghai',
        'MX' => 'America/Mexico_City',
        'ID' => 'Asia/Jakarta',
        'ZA' => 'Africa/Johannesburg',
    ];

    public function resolve(?string $countryCode, string $fallback = 'Australia/Sydney'): string
    {
        $code = strtoupper(trim((string) $countryCode));

        if ($code === '' || strlen($code) !== 2) {
            return $fallback;
        }

        if (isset(self::REPRESENTATIVE[$code])) {
            return self::REPRESENTATIVE[$code];
        }

        $identifiers = \DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, $code);

        return $identifiers[0] ?? $fallback;
    }
}
