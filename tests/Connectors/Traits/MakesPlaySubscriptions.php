<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Imdhemy\GooglePlay\Domain\Purchase\Entity\SubscriptionPurchase;
use Imdhemy\GooglePlay\Domain\Purchase\Subscription\AcknowledgementState;
use Imdhemy\GooglePlay\Domain\Purchase\Subscription\SubscriptionState;
use Imdhemy\GooglePlay\Infrastructure\Transformer\Normalizer;

trait MakesPlaySubscriptions
{
    /**
     * Built through the library's own normalizer, so a fixture is parsed the way a Play response is.
     *
     * @param array<string, mixed> $lineItem overrides for the single line item; a null value drops the field
     * @param array<string, mixed> $purchase overrides for the purchase
     */
    protected function playSubscription(string $productId, array $lineItem = [], array $purchase = []): SubscriptionPurchase
    {
        return Normalizer::create()->normalize(
            $this->playSubscriptionResource($productId, $lineItem, $purchase),
            SubscriptionPurchase::class
        );
    }

    /**
     * A purchases.subscriptionsv2 resource: active, acknowledged, auto-renewing, expiring in a month.
     *
     * @param array<string, mixed> $lineItem
     * @param array<string, mixed> $purchase
     * @return array<string, mixed>
     */
    protected function playSubscriptionResource(string $productId, array $lineItem = [], array $purchase = []): array
    {
        return $purchase + [
            'kind' => 'androidpublisher#subscriptionPurchaseV2',
            'regionCode' => 'US',
            'subscriptionState' => SubscriptionState::ACTIVE->value,
            'acknowledgementState' => AcknowledgementState::ACKNOWLEDGED->value,
            'lineItems' => [
                $lineItem + [
                    'productId' => $productId,
                    'expiryTime' => now()->addMonth()->toIso8601ZuluString(),
                    'autoRenewingPlan' => [
                        'autoRenewEnabled' => true,
                        'recurringPrice' => [
                            'currencyCode' => 'USD',
                            'units' => '9',
                            'nanos' => 990000000,
                        ],
                    ],
                ],
            ],
        ];
    }
}
