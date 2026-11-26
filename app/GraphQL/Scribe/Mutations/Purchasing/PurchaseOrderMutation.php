<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Mutations\Purchasing;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Scribe\Purchasing\Actions\CancelPurchaseOrderAction;
use Kanvas\Scribe\Purchasing\Actions\CreatePurchaseOrderAction;
use Kanvas\Scribe\Purchasing\Actions\SendPurchaseOrderAction;
use Kanvas\Scribe\Purchasing\Actions\SubmitPurchaseOrderForApprovalAction;
use Kanvas\Scribe\Purchasing\Actions\UpdatePurchaseOrderAction;
use Kanvas\Scribe\Purchasing\DataTransferObject\PurchaseOrder as PurchaseOrderData;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Kanvas\Scribe\Purchasing\Services\PurchaseOrderPdfService;

class PurchaseOrderMutation
{
    use ResolvesActingContext;

    public function create(mixed $rootValue, array $request): PurchaseOrder
    {
        $ctx = $this->actingContext();

        return new CreatePurchaseOrderAction(
            PurchaseOrderData::fromMultiple(
                $ctx->app,
                $ctx->user,
                $ctx->company,
                $request['input']
            ),
        )->execute();
    }

    public function update(mixed $rootValue, array $request): PurchaseOrder
    {
        $ctx = $this->actingContext();
        $order = $this->order($request);

        return new UpdatePurchaseOrderAction(
            $order,
            PurchaseOrderData::forUpdate(
                $order,
                $ctx->app,
                $ctx->user,
                $ctx->company,
                $request['input']
            ),
        )->execute();
    }

    public function submit(mixed $rootValue, array $request): PurchaseOrder
    {
        $ctx = $this->actingContext();

        return new SubmitPurchaseOrderForApprovalAction($this->order($request), $ctx->user)->execute();
    }

    public function approve(mixed $rootValue, array $request): PurchaseOrder
    {
        $order = $this->order($request);
        $order->approve($this->actingContext()->user, $request['comment'] ?? null);

        return $order->refresh();
    }

    public function reject(mixed $rootValue, array $request): PurchaseOrder
    {
        $order = $this->order($request);
        $order->reject($this->actingContext()->user, (string) $request['reason']);

        return $order->refresh();
    }

    public function send(mixed $rootValue, array $request): PurchaseOrder
    {
        return new SendPurchaseOrderAction(
            order: $this->order($request),
            user: $this->actingContext()->user,
            recipients: $request['to'],
            message: $request['message'] ?? null,
        )->execute();
    }

    public function generatePdf(mixed $rootValue, array $request): Filesystem
    {
        return new PurchaseOrderPdfService($this->order($request))->generate($this->actingContext()->user);
    }

    public function cancel(mixed $rootValue, array $request): PurchaseOrder
    {
        return new CancelPurchaseOrderAction($this->order($request), $request['reason'] ?? null)->execute();
    }

    private function order(array $request): PurchaseOrder
    {
        $ctx = $this->actingContext();

        return PurchaseOrder::getByIdFromCompanyApp((int) $request['id'], $ctx->company, $ctx->app);
    }
}
