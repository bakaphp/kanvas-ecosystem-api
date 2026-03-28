<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Souk\Orders\Models\Order;

class CalculateOrderCommissionAction
{
    public function __construct(
        protected readonly Order $order,
    ) {
    }

    public function execute(): Order
    {
        $resolved = new ResolveCommissionRateAction($this->order)->execute();
        $rate = $resolved['rate'];
        $source = $resolved['source'];

        $netAmount = (float) ($this->order->total_net_amount ?? 0.0);

        if ($netAmount <= 0) {
            $commissionAmount = 0.0;
            $providerAmount = 0.0;
        } else {
            $commissionAmount = round($netAmount * $rate / 100, 2);
            $providerAmount = $netAmount - $commissionAmount;
        }

        $this->order->commission_rate = $rate;
        $this->order->commission_amount = $commissionAmount;
        $this->order->provider_amount = $providerAmount;

        $this->order->metadata = [
            ...$this->order->metadata ?? [],
            'commission' => [
                'rate' => $rate,
                'amount' => $commissionAmount,
                'provider_amount' => $providerAmount,
                'resolved_from' => $source,
                'calculated_at' => Carbon::now()->toIso8601String(),
            ],
        ];

        $this->order->saveOrFail();

        return $this->order;
    }
}
