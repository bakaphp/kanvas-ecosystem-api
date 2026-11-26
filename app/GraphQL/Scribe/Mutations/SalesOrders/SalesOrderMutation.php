<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Mutations\SalesOrders;

use App\GraphQL\Concerns\ResolvesActingContext;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\SalesOrders\Actions\AcceptSalesOrderAction;
use Kanvas\Scribe\SalesOrders\Actions\CancelSalesOrderAction;
use Kanvas\Scribe\SalesOrders\Actions\ConfirmSalesOrderAction;
use Kanvas\Scribe\SalesOrders\Actions\RegisterCustomerPoAction;
use Kanvas\Scribe\SalesOrders\Actions\ResolveSalesOrderDifferenceAction;
use Kanvas\Scribe\SalesOrders\Actions\ReturnSalesOrderAction;
use Kanvas\Scribe\SalesOrders\Actions\ReviewSalesOrderAction;
use Kanvas\Scribe\SalesOrders\Actions\UpdateSalesOrderAction;
use Kanvas\Scribe\SalesOrders\DataTransferObject\SalesOrder as SalesOrderData;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;

class SalesOrderMutation
{
    use ResolvesActingContext;

    public function register(mixed $rootValue, array $request): SalesOrder
    {
        $ctx = $this->actingContext();

        return new RegisterCustomerPoAction(
            SalesOrderData::from(
                $ctx->app,
                $ctx->user,
                $ctx->company,
                $request['input']
            ),
        )->execute();
    }

    public function update(mixed $rootValue, array $request): SalesOrder
    {
        $ctx = $this->actingContext();
        $order = $this->order($request);

        return new UpdateSalesOrderAction(
            $order,
            SalesOrderData::forUpdate(
                $order,
                $ctx->app,
                $ctx->user,
                $ctx->company,
                $request['input']
            ),
        )->execute();
    }

    public function review(mixed $rootValue, array $request): SalesOrder
    {
        return new ReviewSalesOrderAction($this->order($request), $request['agreement'] ?? [])->execute();
    }

    public function resolveDifference(mixed $rootValue, array $request): SalesOrder
    {
        return new ResolveSalesOrderDifferenceAction(
            $this->order($request),
            (string) $request['key'],
            (string) $request['resolution'],
        )->execute();
    }

    public function confirm(mixed $rootValue, array $request): SalesOrder
    {
        return new ConfirmSalesOrderAction($this->order($request), (bool) $request['feasibility_confirmed'])
            ->execute();
    }

    public function accept(mixed $rootValue, array $request): SalesOrder
    {
        return new AcceptSalesOrderAction(
            $this->order($request),
            Carbon::parse($request['etd']),
            Carbon::parse($request['eta']),
        )->execute();
    }

    public function returnToCommercial(mixed $rootValue, array $request): SalesOrder
    {
        return new ReturnSalesOrderAction($this->order($request), (string) $request['reason'])->execute();
    }

    public function cancel(mixed $rootValue, array $request): SalesOrder
    {
        return new CancelSalesOrderAction($this->order($request), $request['reason'] ?? null)->execute();
    }

    private function order(array $request): SalesOrder
    {
        $ctx = $this->actingContext();

        return SalesOrder::getByIdFromCompanyApp((int) $request['id'], $ctx->company, $ctx->app);
    }
}
