<?php

declare(strict_types=1);

namespace Baka\Support;

/**
 * @todo find a better package for this
 */
class USCityAbbreviations
{
    private static array $cityMap = [
        // Major Cities
        'NYC' => 'New York City',
        'LA' => 'Los Angeles',
        'CHI' => 'Chicago',
        'HOU' => 'Houston',
        'PHX' => 'Phoenix',
        'PHI' => 'Philadelphia',
        'SA' => 'San Antonio',
        'SD' => 'San Diego',
        'DAL' => 'Dallas',

        // Notable Cities
        'ATL' => 'Atlanta',
        'AUS' => 'Austin',
        'BAL' => 'Baltimore',
        'BOS' => 'Boston',
        'BUF' => 'Buffalo',
        'CLT' => 'Charlotte',
        'CIN' => 'Cincinnati',
        'CLE' => 'Cleveland',
        'COL' => 'Columbus',
        'DEN' => 'Denver',
        'DET' => 'Detroit',
        'DFW' => 'Dallas-Fort Worth',
        'ELP' => 'El Paso',
        'FTW' => 'Fort Worth',
        'FRE' => 'Fresno',
        'GSB' => 'Greensboro',
        'HNL' => 'Honolulu',
        'IND' => 'Indianapolis',
        'JAX' => 'Jacksonville',
        'KC' => 'Kansas City',
        'LV' => 'Las Vegas',
        'LB' => 'Long Beach',
        'LAX' => 'Los Angeles',
        'MEM' => 'Memphis',
        'MIA' => 'Miami',
        'MIL' => 'Milwaukee',
        'MSP' => 'Minneapolis',
        'NSH' => 'Nashville',
        'NO' => 'New Orleans',
        'NOLA' => 'New Orleans',
        'OAK' => 'Oakland',
        'OKC' => 'Oklahoma City',
        'OMA' => 'Omaha',
        'PDX' => 'Portland',
        'PIT' => 'Pittsburgh',
        'RAL' => 'Raleigh',
        'SAC' => 'Sacramento',
        'SLC' => 'Salt Lake City',
        'SAT' => 'San Antonio',
        'SF' => 'San Francisco',
        'SFO' => 'San Francisco',
        'SF Bay Area' => 'San Francisco',
        'SJ' => 'San Jose',
        'SEA' => 'Seattle',
        'STL' => 'St. Louis',
        'TPA' => 'Tampa',
        'TUC' => 'Tucson',
        'DC' => 'Washington D.C.',
        'WDC' => 'Washington D.C.',

        // State Capitals
        'ALB' => 'Albany',
        'ANN' => 'Annapolis',
        'BDL' => 'Hartford',
        'BIS' => 'Bismarck',
        'BOI' => 'Boise',
        'CAR' => 'Carson City',
        'CHA' => 'Charleston',
        'CHY' => 'Cheyenne',
        'DSM' => 'Des Moines',
        'HLN' => 'Helena',
        'JUN' => 'Juneau',
        'LAN' => 'Lansing',
        'LIT' => 'Little Rock',
        'MON' => 'Montgomery',
        'MSN' => 'Madison',
        'OLY' => 'Olympia',
        'PRO' => 'Providence',
        'RIC' => 'Richmond',
        'SAL' => 'Salem',
        'SAN' => 'Santa Fe',
        'SPR' => 'Springfield',
        'TAL' => 'Tallahassee',
        'TOP' => 'Topeka',
        'TRE' => 'Trenton',

        // Common Regional Names
        'BKLYN' => 'Brooklyn',
        'BX' => 'Bronx',
        'SI' => 'Staten Island',
        'QNS' => 'Queens',
        'MAN' => 'Manhattan',
        'SGV' => 'San Gabriel Valley',
        'IE' => 'Inland Empire',
        'OC' => 'Orange County',
        'RDU' => 'Raleigh-Durham',
        'DMV' => 'DC-Maryland-Virginia',
    ];

    /**
     * Convert a city abbreviation to its full name
     *
     * @param string $abbreviation The city abbreviation
     * @return string The full city name or original input if not found
     */
    public static function expand(string $abbreviation): string
    {
        $key = Str::upper(Str::trim($abbreviation));

        return self::$cityMap[$key] ?? $abbreviation;
    }

    /**
     * Convert multiple abbreviations in a text
     *
     * @param string $text Text containing city abbreviations
     * @return string Text with expanded city names
     */
    public static function expandInText(string $text): string
    {
        // Create a collection of replacements
        return collect(self::$cityMap)
            ->reduce(function ($text, $fullName, $abbr) {
                $pattern = '/\b' . preg_quote($abbr, '/') . '\b/i';

                return preg_replace($pattern, $fullName, $text);
            }, $text);
    }

    /**
     * Get all available abbreviations
     *
     * @return array Array of all abbreviations and their full names
     */
    public static function getAllCities(): array
    {
        return collect(self::$cityMap)
            ->map(fn ($name) => Str::title($name))
            ->toArray();
    }

    /**
     * Search for cities by partial match of abbreviation or full name
     *
     * @param string $term Search term
     * @return array Matching cities
     */
    public static function search(string $term): array
    {
        return collect(self::$cityMap)
            ->filter(function ($fullName, $abbr) use ($term) {
                return Str::contains(Str::lower($abbr), Str::lower($term)) ||
                       Str::contains(Str::lower($fullName), Str::lower($term));
            })
            ->toArray();
    }

    /**
     * Fuzzy search for cities using Str::is() for pattern matching
     *
     * @param string $pattern Search pattern (can include wildcards)
     * @return array Matching cities
     */
    public static function fuzzySearch(string $pattern): array
    {
        return collect(self::$cityMap)
            ->filter(function ($fullName, $abbr) use ($pattern) {
                return Str::is($pattern, $abbr) || Str::is($pattern, $fullName);
            })
            ->toArray();
    }

    /**
     * Get similar city names using Str::similar()
     *
     * @param string $input Input string to find similar matches for
     * @param int $threshold Similarity threshold (default: 80%)
     * @return array Similar cities
     */
    public static function findSimilar(string $input, int $threshold = 80): array
    {
        return collect(self::$cityMap)
            ->filter(function ($fullName, $abbr) use ($input, $threshold) {
                return similar_text($fullName, $input, $percent) && $percent >= $threshold ||
                       similar_text($abbr, $input, $percent) && $percent >= $threshold;
            })
            ->toArray();
    }

    /**
     * Get cities that start with a specific string
     *
     * @param string $prefix Starting string
     * @return array Matching cities
     */
    public static function startsWith(string $prefix): array
    {
        return collect(self::$cityMap)
            ->filter(fn ($fullName) => Str::startsWith(Str::lower($fullName), Str::lower($prefix)))
            ->toArray();
    }

    /**
     * Slugify city names for URL-friendly versions
     *
     * @param string $abbreviation City abbreviation
     * @return string URL-friendly version of the city name
     */
    public static function slug(string $abbreviation): string
    {
        $cityName = self::expand($abbreviation);

        return Str::slug($cityName);
    }
}
