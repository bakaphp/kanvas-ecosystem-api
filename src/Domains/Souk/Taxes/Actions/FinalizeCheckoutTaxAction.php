<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Taxes\Enums\ConfigurationEnum;
use Kanvas\Souk\Taxes\Enums\GatewayAttemptStatusEnum;
use Kanvas\Souk\Taxes\Enums\TaxTreatmentEnum;
use Kanvas\Souk\Taxes\Providers\TaxProviderResolver;

class FinalizeCheckoutTaxAction
{
    public function __construct(
        private readonly Order $order,
        private readonly Apps $app,
    ) {
    }

    public function execute(): array
    {
        if (! $this->app->get(ConfigurationEnum::TAX_ENGINE_ENABLED->value)) {
            return [
                'snapshotUuid' => null,
                'inputHash' => null,
                'payable' => $this->order->getTotalDueAmount(),
                'gatewayDue' => $this->order->getTotalDueAmount(),
                'taxEnabled' => false,
            ];
        }

        $context = new BuildTaxContextAction($this->order, $this->app)->execute();
        $provider = new TaxProviderResolver($this->app)->resolve($context);
        $result = $provider->calculate($context);

        if ($result->treatment === TaxTreatmentEnum::NOT_COLLECTING) {
            if (! $this->app->get(ConfigurationEnum::TAX_FAIL_OPEN->value)) {
                throw new ValidationException('tax_not_configured_for_destination');
            }

            return [
                'snapshotUuid' => null,
                'inputHash' => null,
                'payable' => $this->order->getTotalDueAmount(),
                'gatewayDue' => $this->order->getTotalDueAmount(),
                'taxEnabled' => true,
            ];
        }

        $this->order->tax_amount = (float) $result->totalTax;
        $this->order->tax_source = 'souk';
        $this->order->recomputeTotals(autoSave: false);

        $snapshot = DB::connection('commerce')->transaction(function () use ($context, $result) {
            $this->order->saveOrFail();

            $snapshot = new PersistTaxSnapshotAction(
                context: $context,
                result: $result,
                app: $this->app,
                provider: $result->treatment->value,
                orderId: $this->order->id,
                cartSessionId: $this->order->checkout_token,
            )->execute();

            $snapshot->gateway_attempt_key = hash('sha256', $this->order->uuid . microtime(true));
            $snapshot->gateway_attempt_status = GatewayAttemptStatusEnum::PENDING->value;
            $snapshot->saveOrFail();

            return $snapshot;
        });

        return [
            'snapshotUuid' => $snapshot->uuid,
            'inputHash' => $snapshot->input_hash,
            'payable' => $this->order->total_payable_amount,
            'gatewayDue' => $this->order->getTotalDueAmount(),
            'taxEnabled' => true,
        ];
    }
}
