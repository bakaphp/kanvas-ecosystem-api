<?php

declare(strict_types=1);

namespace App\GraphQL\HumanResources\Concerns;

use Baka\Contracts\CompanyInterface;
use DateTimeInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;

trait ResolvesActingContext
{
    /**
     * @return array{0: Users, 1: Apps, 2: CompanyInterface}
     */
    protected function actingContext(): array
    {
        $user = auth()->user();

        return [$user, app(Apps::class), $user->getCurrentCompany()];
    }

    /** The GraphQL Date scalar hydrates to Carbon; DTOs want a Y-m-d string. */
    protected function normalizeDate(mixed $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : (string) $value;
    }
}
