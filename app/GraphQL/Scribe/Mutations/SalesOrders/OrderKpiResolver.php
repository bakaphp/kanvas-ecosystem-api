<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Mutations\SalesOrders;

use App\GraphQL\Concerns\ResolvesActingContext;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\SalesOrders\Kpis\OrderKpiService;

class OrderKpiResolver
{
    use ResolvesActingContext;

    public function report(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();

        return new OrderKpiService()->report(
            $ctx->app,
            $ctx->company,
            Carbon::parse($request['from']),
            Carbon::parse($request['to'])
        );
    }
}
