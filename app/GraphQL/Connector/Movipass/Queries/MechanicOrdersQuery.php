<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Movipass\Queries;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\GetMechanicAssignedOrdersAction;

class MechanicOrdersQuery
{
    public function getAssignedOrders(mixed $root, array $args): Builder
    {
        $user = auth()->user();
        $app = app(Apps::class);

        return new GetMechanicAssignedOrdersAction($user, $app)->execute();
    }
}
