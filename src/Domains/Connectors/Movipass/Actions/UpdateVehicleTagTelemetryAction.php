<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\PasoRapido\Services\PasoRapidoService;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Souk\Orders\Models\Order;
use Throwable;

class UpdateVehicleTagTelemetryAction
{
    public function __construct(
        protected readonly Products $vehicle,
        protected readonly Order $order,
        protected readonly AppInterface $app,
        protected readonly string $tag,
        protected readonly float $rechargeAmount,
        protected readonly ?PasoRapidoService $service = null,
    ) {
    }

    public function execute(): array
    {
        if (trim($this->tag) === '') {
            return ['status' => 'skipped', 'reason' => 'empty tag'];
        }

        $now = Carbon::now();
        $attributes = [
            ['name' => 'last_recharge_date', 'value' => $now->toIso8601String()],
            ['name' => 'last_recharge_amount', 'value' => (string) $this->rechargeAmount],
            ['name' => 'last_order_id', 'value' => (string) $this->order->getId()],
        ];

        $balance = null;
        $balanceError = null;

        try {
            $service = $this->service ?? new PasoRapidoService($this->app, $this->order->company);
            $verifyResponse = $service->fetchTagBalance($this->tag);
            $balance = $verifyResponse->balance;

            $attributes[] = ['name' => 'tag_balance', 'value' => (string) $balance];
            $attributes[] = ['name' => 'tag_balance_fetched_at', 'value' => $now->toIso8601String()];
        } catch (Throwable $e) {
            report($e);
            $balanceError = $e->getMessage();
        }

        $attributeAuthor = $this->vehicle->user ?? $this->order->user;
        $this->vehicle->addAttributes($attributeAuthor, $attributes);
        $this->vehicle->save();

        return [
            'status' => 'success',
            'product_id' => $this->vehicle->getId(),
            'tag' => $this->tag,
            'last_recharge_amount' => $this->rechargeAmount,
            'tag_balance' => $balance,
            'tag_balance_error' => $balanceError,
        ];
    }
}
