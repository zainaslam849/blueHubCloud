<?php

namespace App\Support;

/**
 * Parses the From/To party strings PBXware puts in CDR rows.
 *
 * Internal parties always arrive decorated as "Name (extension)" — e.g.
 * "Darius (2009)" or "AU_Sales_RG (3001)" — never as a bare extension
 * number. External parties are plain numbers ("+61438884087", "0414085994"),
 * and feature dials are star codes ("*78").
 *
 * Getting this right is what makes call direction derivable: PBXware's CDR
 * payload has no direction column, so it has to be inferred from which side
 * of the call is internal.
 */
class CdrParty
{
    /**
     * @return array{extension: ?string, name: ?string, internal: bool, feature_code: bool}
     */
    public static function parse(mixed $value): array
    {
        $empty = ['extension' => null, 'name' => null, 'internal' => false, 'feature_code' => false];

        if (! is_string($value)) {
            return $empty;
        }

        $raw = trim($value);
        if ($raw === '') {
            return $empty;
        }

        // Feature dials such as *78 / *79 are internal but have no extension.
        if (preg_match('/^\*\d+$/', $raw)) {
            return ['extension' => null, 'name' => null, 'internal' => true, 'feature_code' => true];
        }

        // "Darius (2009)" / "AU_Sales_RG (3001)"
        if (preg_match('/^(.*?)\s*\((\d{2,6})\)\s*$/', $raw, $m)) {
            $name = trim($m[1]);

            return [
                'extension' => $m[2],
                'name' => $name !== '' ? $name : null,
                'internal' => true,
                'feature_code' => false,
            ];
        }

        // Bare extension (not seen in current payloads, but harmless to support).
        if (preg_match('/^\d{2,6}$/', $raw)) {
            return ['extension' => $raw, 'name' => null, 'internal' => true, 'feature_code' => false];
        }

        // Anything else is an external number.
        return $empty;
    }

    /**
     * Derive call direction from the two party strings.
     *
     * external -> external happens on trunk/DID legs; the company is still
     * the receiving side, so it is treated as inbound. Such rows carry no
     * internal party and therefore never attribute to an extension.
     */
    public static function direction(mixed $from, mixed $to): string
    {
        $fromParty = self::parse($from);
        $toParty = self::parse($to);

        if ($fromParty['internal'] && $toParty['internal']) {
            return 'internal';
        }

        if ($fromParty['internal']) {
            return 'outbound';
        }

        if ($toParty['internal']) {
            return 'inbound';
        }

        return 'inbound';
    }
}
