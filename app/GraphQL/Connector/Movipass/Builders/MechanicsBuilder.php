<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Movipass\Builders;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Repositories\MechanicsRepository;

class MechanicsBuilder
{
    public function build(mixed $root, array $args): Builder
    {
        return MechanicsRepository::query(
            app: app(Apps::class),
            companyId: isset($args['company_id']) ? (int) $args['company_id'] : null,
            availability: $args['availability'] ?? null,
            serviceType: $args['service_type'] ?? null,
        );
    }
}
