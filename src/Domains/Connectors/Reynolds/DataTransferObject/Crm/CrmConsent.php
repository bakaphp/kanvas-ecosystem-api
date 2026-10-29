<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\DataTransferObject\Crm;

use Spatie\LaravelData\Data;

/**
 * What the CRM says about contact consent, already translated to booleans by the client.
 */
class CrmConsent extends Data
{
    public function __construct(
        public readonly ?CrmOptOut $opt_out = null
    ) {
    }

    /**
     * Extensions released before this shape sent `opt_out` as a string. Anything that is not an object
     * of booleans is dropped instead of failing the request, and a member that is not a real boolean
     * counts as not opted out.
     *
     * @param array<string, mixed> $properties
     *
     * @return array<string, mixed>
     */
    public static function prepareForPipeline(array $properties): array
    {
        $optOut = $properties['opt_out'] ?? null;

        if (! is_array($optOut)) {
            unset($properties['opt_out']);

            return $properties;
        }

        $properties['opt_out'] = [
            'email' => ($optOut['email'] ?? null) === true,
            'phone' => ($optOut['phone'] ?? null) === true,
        ];

        return $properties;
    }
}
