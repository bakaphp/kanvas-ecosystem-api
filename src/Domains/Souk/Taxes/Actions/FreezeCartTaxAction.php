<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Actions;

use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Shipping\Enums\ShippingConditionEnum;
use Kanvas\Souk\Taxes\DataTransferObject\TaxContext;
use Kanvas\Souk\Taxes\DataTransferObject\TaxResult;
use Kanvas\Souk\Taxes\Enums\GatewayAttemptStatusEnum;
use Kanvas\Souk\Taxes\Enums\TaxConditionEnum;
use Kanvas\Souk\Taxes\Enums\TaxProviderEnum;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Kanvas\Users\Models\Users;
use Wearepixel\Cart\Cart;

class FreezeCartTaxAction
{
    public function __construct(
        private readonly Cart $cart,
        private readonly Apps $app,
        private readonly CompanyInterface $company,
        private readonly Regions $region,
        private readonly ?Users $user = null
    ) {
    }

    public function execute(): ?OrderTaxSnapshot
    {
        $estimate = new EstimateCartTaxAction($this->cart, $this->app, $this->user);
        $result = $estimate->execute();
        $context = $estimate->context();
        $provider = $estimate->provider();

        if ($result === null || $context === null || $provider === null) {
            return null;
        }

        return DB::connection('commerce')->transaction(function () use ($context, $result, $provider): OrderTaxSnapshot {
            $snapshot = new PersistTaxSnapshotAction(
                context: $context,
                result: $result,
                app: $this->app,
                provider: TaxProviderEnum::of($provider)->value,
                cartSessionId: $this->cart->getSessionKey(),
            )->execute();

            $snapshot->gateway_attempt_key = hash('sha256', $this->cart->getSessionKey() . microtime(true));
            $snapshot->gateway_attempt_status = GatewayAttemptStatusEnum::PENDING->value;
            $snapshot->checkout_payload = $this->buildPayload($context, $result);
            $snapshot->saveOrFail();

            return $snapshot;
        });
    }

    private function buildPayload(TaxContext $context, TaxResult $result): array
    {
        $lines = [];

        foreach ($this->cart->getContent() as $item) {
            $variant = Variants::find((int) $item['id']);

            $lines[] = [
                'variant_id' => (int) $item['id'],
                'variant_uuid' => $variant?->uuid,
                'product_name' => $variant?->product?->name ?? (string) ($item['name'] ?? ''),
                'product_sku' => $variant?->sku ?? '',
                'variant_name' => $variant?->name ?? (string) ($item['name'] ?? ''),
                'quantity' => (int) $item['quantity'],
                'unit_price_net_amount' => (float) $item['price'],
            ];
        }

        $subtotal = (float) $this->cart->getSubTotalWithoutConditions();
        $payable = (float) $this->cart->getTotal();
        $shipping = $this->conditionAmount(ShippingConditionEnum::NAME->value);
        $chargedTax = $this->conditionAmount(TaxConditionEnum::NAME->value);

        return [
            'lines' => $lines,
            'lines_hash' => CompleteCheckoutFromSnapshotAction::linesHash($lines),
            'input_hash' => $context->inputHash(),
            'currency' => $context->currency,
            'shipping_destination' => [
                'country' => $context->shipTo->country,
                'city' => $context->shipTo->city,
                'postal_code' => $context->shipTo->postalCode,
            ],
            'order_context' => [
                'companies_id' => $this->company->getId(),
                'region_id' => $this->region->getId(),
                'users_id' => $this->user?->getId(),
                'people_id' => $this->user?->getAppProfile($this->app)->people_id,
            ],
            'totals' => [
                'subtotal' => $subtotal,
                'shipping' => $shipping,
                'tax' => (float) $result->totalTax,
                'discount' => round(max(0.0, $subtotal + $shipping + $chargedTax - $payable), 2),
                'payable' => $payable,
                'wallet_tender' => 0.0,
                'gateway_due' => $payable,
            ],
        ];
    }

    private function conditionAmount(string $name): float
    {
        if ($this->cart->getCondition($name) === null) {
            return 0.0;
        }

        return (float) $this->cart->getCalculatedValueForCondition($name);
    }
}
