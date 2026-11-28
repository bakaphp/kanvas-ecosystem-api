<?php

declare(strict_types=1);

namespace Kanvas\Souk\Mailings\Audiences;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Notifications\Mailings\Contracts\AudienceResolver;
use Kanvas\Souk\Orders\Services\OrderReportService;

class ActiveUsersAudience implements AudienceResolver
{
    public function resolve(Apps $app, ?Companies $company, array $criteria): array
    {
        if ($company === null) {
            throw new ValidationException('The active_users audience needs a company.');
        }

        return new OrderReportService($app, $company)
            ->activeUserIdsQuery(
                orderTypeNames: $criteria['order_types'] ?? null,
                since: $criteria['since'] ?? null,
                until: $criteria['until'] ?? null,
                timezone: $criteria['timezone'] ?? null,
            )
            ->pluck('users_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
