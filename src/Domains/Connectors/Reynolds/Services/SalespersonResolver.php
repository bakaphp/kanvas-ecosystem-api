<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Services;

use Kanvas\Companies\Models\Companies;

class SalespersonResolver
{
    /**
     * R&R ships the name in two shapes: "LastName, FirstName" (LDU/OSL
     * PrimarySalesPerson) and "FirstName LastName" (RCI disposition
     * RCIDispositionPrimarySalesperson). Scoped to the dealer's users so a
     * cross-tenant name collision can't reassign ownership to another company.
     */
    public static function resolveUserId(?string $name, Companies $company): ?int
    {
        [$firstname, $lastname] = self::splitName($name);

        if ($firstname === null || $lastname === null) {
            return null;
        }

        // Qualify the columns — users() is a HasManyThrough join and
        // users_associated_apps also carries firstname/lastname, so an
        // unqualified where is ambiguous.
        $user = $company->users()
            ->where('users.firstname', $firstname)
            ->where('users.lastname', $lastname)
            ->first();

        return $user?->getId();
    }

    /**
     * @return array{0: ?string, 1: ?string} [firstname, lastname]
     */
    private static function splitName(?string $name): array
    {
        $raw = trim((string) $name);
        if ($raw === '') {
            return [null, null];
        }

        if (str_contains($raw, ',')) {
            [$lastname, $firstname] = array_pad(array_map('trim', explode(',', $raw, 2)), 2, null);
        } else {
            [$firstname, $lastname] = array_pad(array_map('trim', explode(' ', $raw, 2)), 2, null);
        }

        if (empty($firstname) || empty($lastname)) {
            return [null, null];
        }

        return [$firstname, $lastname];
    }
}
