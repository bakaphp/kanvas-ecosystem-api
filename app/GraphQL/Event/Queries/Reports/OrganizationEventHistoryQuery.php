<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Queries\Reports;

use App\GraphQL\Concerns\ResolvesActingContext;
use Carbon\Carbon;
use Kanvas\Event\Reports\Repositories\OrganizationEventActivityRepository;
use Kanvas\Guild\Organizations\Models\Organization;

class OrganizationEventHistoryQuery
{
    use ResolvesActingContext;

    /**
     * @return list<array<string, mixed>>
     */
    public function __invoke(mixed $rootValue, array $args): array
    {
        $ctx = $this->actingContext();

        /** @var Organization $organization */
        $organization = Organization::getByIdFromCompanyApp((int) $args['organization_id'], $ctx->company, $ctx->app);

        return OrganizationEventActivityRepository::historyFor(
            $organization,
            isset($args['from_date']) ? Carbon::parse((string) $args['from_date']) : null,
            isset($args['to_date']) ? Carbon::parse((string) $args['to_date']) : null,
            max(1, min((int) ($args['limit'] ?? 100), 500))
        );
    }
}
