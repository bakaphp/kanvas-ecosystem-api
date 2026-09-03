<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Services;

/**
 * Collapses the legal-suffix / punctuation variants of a company name so the
 * same real-world organization resolves to one record at ingest time
 * ("MCTEKK" vs "MCTEKK SRL", "Grupo Carol, SAS" vs "GRUPO CAROL, S.A.").
 *
 * It only strips a trailing legal-entity suffix and tidies whitespace/punctuation —
 * it deliberately does NOT uppercase, because the DB string comparison is already
 * case-insensitive (utf8_general_ci) and we want to keep a readable display name.
 *
 * Suffix list is conservative and Dominican-Republic-aware (SRL, S.A., SAS, EIRL,
 * C. por A.) plus common international and German forms. Matching is dot/space/case
 * insensitive, and a name that is ENTIRELY a suffix (no separator before it) is left
 * untouched so we never blank a name.
 */
class OrganizationNameNormalizerService
{
    /**
     * Order matters: longer / more specific suffixes first so e.g. "SAS" is tried
     * before "SA" and "SRL" before "SA". Each letter allows an optional trailing
     * dot and optional spaces ("S. A.", "S.A.", "S A").
     */
    private const SUFFIXES = [
        'S\.?\s?R\.?\s?L\.?\s?D\.?\s?E\.?\s?C\.?\s?V',   // SRL de CV
        'S\.?\s?A\.?\s?D\.?\s?E\.?\s?C\.?\s?V',          // SA de CV
        'E\.?\s?I\.?\s?R\.?\s?L',                        // EIRL
        'S\.?\s?R\.?\s?L',                               // SRL
        'S\.?\s?A\.?\s?S',                               // SAS
        'S\.?\s?A',                                      // SA / S.A. / S. A.
        'C\.?\s?POR\s?A',                                // C. por A.
        'L\.?\s?L\.?\s?C',                               // LLC
        'L\.?\s?T\.?\s?D\.?A?',                          // LTD / LTDA
        'CORP(?:ORATION)?',                              // CORP / CORPORATION
        'INC(?:ORPORATED)?',                             // INC
        '&?\s?CO',                                       // CO / & CO
        'GMBH\s?&\s?CO\.?\s?KG',                         // GmbH & Co. KG
        'PARTG\s?MBB',                                   // Partnerschaftsgesellschaft mbB
        'MBB',                                           // mbB (professional-liability partnership)
        'GMBH',                                          // GmbH
        'GBR',                                           // GbR
        'OHG',                                           // OHG
        'KG',                                            // KG
        'UG\s?\(?HAFTUNGSBESCHR[ÄA]NKT\)?',              // UG (haftungsbeschränkt)
        'UG',                                            // UG
        'AG',                                            // AG
        'E\.?\s?V',                                      // e.V.
    ];

    public static function normalize(string $name): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ($clean === '') {
            return $clean;
        }

        $pattern = '/[\s,]+(?:' . implode('|', self::SUFFIXES) . ')\.?\s*$/iu';
        $stripped = (string) preg_replace($pattern, '', $clean);
        $stripped = trim($stripped, " \t\n\r\0\x0B,.");

        return $stripped !== '' ? $stripped : $clean;
    }
}
