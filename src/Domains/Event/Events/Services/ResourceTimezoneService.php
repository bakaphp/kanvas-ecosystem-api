<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Services;

use Illuminate\Database\Eloquent\Model;
use Kanvas\CustomFields\Traits\HasCustomFields;

/**
 * Single source of truth for "what wall clock does this resource operate on".
 * Both the schedule writer and the slot generator must agree, otherwise the same
 * DTSTART literal resolves to two different instants and days shift.
 */
class ResourceTimezoneService
{
    public const CUSTOM_FIELD = 'timezone';
    public const FALLBACK = 'America/Santo_Domingo';

    public static function resolve(?Model $resource): string
    {
        if ($resource === null) {
            return self::FALLBACK;
        }

        $candidates = [
            $resource->tz,
            self::fromCustomField($resource),
            $resource->company?->timezone,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && in_array($candidate, timezone_identifiers_list(), true)) {
                return $candidate;
            }
        }

        return self::FALLBACK;
    }

    protected static function fromCustomField(Model $resource): ?string
    {
        if (! in_array(HasCustomFields::class, class_uses_recursive($resource), true)) {
            return null;
        }

        $timezone = $resource->get(self::CUSTOM_FIELD);

        return is_string($timezone) ? $timezone : null;
    }
}
