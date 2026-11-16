<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Actions\Concerns;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;

trait ResolvesZohoOwner
{
    /**
     * Zoho sends a record's owner as `Owner: {name, id, email}`; the email is the only part a
     * Kanvas user can be matched on. Null when nobody in the app has it.
     */
    protected function resolveZohoOwner(AppInterface $app, mixed $zohoOwner): ?Users
    {
        $email = is_array($zohoOwner) && is_scalar($zohoOwner['email'] ?? null)
            ? Str::trimToNull((string) $zohoOwner['email'])
            : null;

        if ($email === null) {
            return null;
        }

        return UsersAssociatedApps::query()
            ->fromApp($app)
            ->where('email', $email)
            ->first()?->user;
    }
}
