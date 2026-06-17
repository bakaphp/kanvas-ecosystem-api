<?php

declare(strict_types=1);

namespace Baka\Support;

class AddressParser
{
    /**
     * Parse a US street address into components.
     *
     * Sources like driver-license OCR are inconsistent about comma placement
     * ("ST, City, IN 46410", "ST, City, IN, 46410", "ST City IN 46410", or a
     * two-line form). Rather than enumerate every layout, anchor on the one
     * reliably-structured tail — the two-letter state followed by a 5(-4) ZIP at
     * the end — and tolerate any comma/space arrangement around it. Whatever
     * precedes the state is the street + city, split on the last comma (or the
     * last word when there is none).
     *
     * @return array{address: string, city: string, state: string, zipcode: string}|null
     */
    public static function parse(string $fullAddress): ?array
    {
        $normalized = trim(preg_replace('/\s+/', ' ', str_replace("\n", ', ', $fullAddress)));

        if (! preg_match('/^(.*?)[,\s]+([A-Za-z]{2})[,\s]+(\d{5}(?:-\d{4})?)\s*$/', $normalized, $matches)) {
            return null;
        }

        $streetAndCity = trim($matches[1], ' ,');
        $state = strtoupper($matches[2]);
        $zipcode = $matches[3];

        if (str_contains($streetAndCity, ',')) {
            $parts = array_map('trim', explode(',', $streetAndCity));
            $city = array_pop($parts);
            $street = implode(', ', $parts);
        } else {
            $words = explode(' ', $streetAndCity);
            $city = array_pop($words);
            $street = implode(' ', $words);
        }

        return [
            'address' => $street,
            'city' => $city,
            'state' => $state,
            'zipcode' => $zipcode,
        ];
    }
}
