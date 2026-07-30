<?php

declare(strict_types=1);

namespace App\GraphQL\Concerns;

use Baka\Support\DateHelper;
use DateTimeInterface;
use Kanvas\Apps\Models\Apps;

/**
 * Resolves the acting tenant context (logged-in user + current app + current company) for a GraphQL
 * resolver, and normalizes GraphQL date inputs. Shared across domain resolvers.
 */
trait ResolvesActingContext
{
    protected function actingContext(): ActingContext
    {
        $user = auth()->user();

        return new ActingContext(
            $user,
            app(Apps::class),
            $user->getCurrentCompany()
        );
    }

    /**
     * The GraphQL Date scalar hydrates to Carbon; DTOs want a Y-m-d string. Falls back to the shared
     * DateHelper for any string input, then to the raw value if it can't be parsed.
     */
    protected function normalizeDate(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return DateHelper::normalizeDate((string) $value) ?? (string) $value;
    }
}
