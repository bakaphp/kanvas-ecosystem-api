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
 * The person behind a caller's phone number. Every voice read and write resolves the caller here,
 * so the context a call opens with and the capture and transcript it ends with agree on who it was.
 */
class VoiceCallerLookupService
{
    public static function find(AppInterface $app, Companies $company, string $phone): ?People
    {
        // A withheld caller id has no digits, and getByPhoneNumber() with no numbers matches everyone.
        $numbers = Str::phoneSearchTerms($phone);
        if ($numbers === []) {
            return null;
        }

        $people = PeoplesRepository::getByPhoneNumber($app, $company, $numbers)->get();

        // Prefer someone with an active lead; else the first match.
        return $people->first(
            fn (People $p): bool => LeadsRepository::getPeopleActiveLead($p) !== null
        ) ?? $people->first();
    }

    /**
     * Capture stores the phone as the firstname until the caller gives a name.
     */
    public static function isPlaceholderName(?string $name): bool
    {
        $name = trim((string) $name);

        return $name === '' || preg_match('/^[\d\s+()\-]+$/', $name) === 1;
    }
}
