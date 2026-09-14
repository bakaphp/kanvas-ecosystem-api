<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Movipass\Builders;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Repositories\MechanicOrdersRepository;

class MechanicOrdersBuilder
{
    public function build(mixed $root, array $args): Builder
    {
        $providerCompanyId = isset($args['provider_id']) ? (int) $args['provider_id'] : null;

        // Without an explicit mechanic the query belongs to the caller, unless they asked for all.
        $seesEveryMechanic = ($args['all'] ?? false) && ! isset($args['mechanic_id']);
        $mechanicId = match (true) {
            isset($args['mechanic_id']) => (int) $args['mechanic_id'],
            $seesEveryMechanic => null,
            default => (int) auth()->user()->getId(),
        };

        return MechanicOrdersRepository::query(
            app: app(Apps::class),
            mechanicId: $mechanicId,
            mechanicFilter: $args['mechanic_filter'] ?? null,
            providerCompanyId: $providerCompanyId,
        );
    }
}
