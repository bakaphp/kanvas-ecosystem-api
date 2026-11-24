<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;

/**
 * The person behind a caller's phone number, as the voice runtime's writes see it: capture
 * creates them, and the transcript is filed on whoever capture found or created.
 */
class VoiceCallerLookup
{
    public static function find(AppInterface $app, Companies $company, string $phone): ?People
    {
        $people = PeoplesRepository::getByPhoneNumber(
            app: $app,
            company: $company,
            phoneNumbers: array_values(array_unique(array_filter([
                Str::sanitizePhoneNumber($phone),
                Str::normalizePhoneNumber($phone),
            ]))),
        )->get();

        if ($people->isEmpty()) {
            return null;
        }

        // Prefer someone with an active lead; else the first match.
        return $people->first(
            fn (People $p): bool => LeadsRepository::getPeopleActiveLead($p) !== null
        ) ?? $people->first();
    }
}
