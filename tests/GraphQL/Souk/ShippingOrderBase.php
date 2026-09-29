<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Baka\Support\Str;
use Illuminate\Testing\TestResponse;
use Kanvas\Inventory\Variants\Enums\ConfigurationEnum as VariantConfigurationEnum;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Locations\Models\Countries;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Shipping\RateCards\Actions\ImportRateCardsAction;

abstract class ShippingOrderBase extends OrderBase
{
    protected string $variantId;
    protected string $cartIdentifier;

    public function setUp(): void
    {
        parent::setUp();

        $productResponse = $this->createProduct()->json()['data']['createProduct'];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: [
                'id' => $this->warehouseResponse['id'],
            ],
        )->json()['data']['createVariant'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $this->channelResponse['id'],
            warehouseData: [
                'id' => $this->warehouseResponse['id'],
            ]
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        $this->variantId = $variantResponse['id'];
        $this->cartIdentifier = (string) Str::uuid();
        Countries::firstOrCreate(['code' => 'us'], ['name' => 'United States']);
        $this->configureRateCards();
        $this->setVariantWeight(400);
    }

    protected function configureRateCards(?callable $mutate = null): void
    {
        $cards = json_decode(
            file_get_contents(base_path('database/seeders/data/shipping/inposdom/shipping_rate_cards.json')),
            true
        );

        foreach ($cards['inposdom']['services'] as &$service) {
            $service['currency'] = $this->region->currency->code;
        }
        unset($service);

        new ImportRateCardsAction($this->apps, $this->company, $mutate ? $mutate($cards) : $cards)->execute();
    }

    protected function setVariantWeight(int $grams): void
    {
        Variants::findOrFail($this->variantId)->addAttribute(VariantConfigurationEnum::WEIGHT_UNIT->value, $grams);
    }

    protected function cartHeaders(): array
    {
        return [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-Identifier' => $this->cartIdentifier,
        ];
    }

    protected function addToCart(int $quantity = 1): TestResponse
    {
        return $this->graphQL('
            mutation addToCart($items: [CartItemInput!]!) {
                addToCart(items: $items) {
                    id
                }
            }
        ', [
            'items' => [
                [
                    'variant_id' => $this->variantId,
                    'quantity' => $quantity,
                ],
            ],
        ], [], $this->cartHeaders());
    }

    protected function updateCartQuantity(int $quantity): TestResponse
    {
        return $this->graphQL('
            mutation updateCart($variantId: ID!, $quantity: Int!) {
                updateCart(variant_id: $variantId, quantity: $quantity) {
                    id
                }
            }
        ', [
            'variantId' => $this->variantId,
            'quantity' => $quantity,
        ], [], $this->cartHeaders());
    }

    protected function removeFromCart(): TestResponse
    {
        return $this->graphQL('
            mutation removeFromCart($variantId: ID!) {
                removeFromCart(variant_id: $variantId) {
                    id
                }
            }
        ', [
            'variantId' => $this->variantId,
        ], [], $this->cartHeaders());
    }

    protected function shippingQuotes(string $country = 'US'): array
    {
        $quotes = $this->graphQL('
            query shippingQuotes($destination: ShippingDestinationInput!) {
                shippingQuotes(destination: $destination) {
                    provider
                    service_code
                    service_name
                    amount
                    currency
                    transit_min_days
                    transit_max_days
                }
            }
        ', [
            'destination' => ['country' => $country],
        ], [], $this->cartHeaders())->json('data.shippingQuotes') ?? [];

        return array_column($quotes, null, 'service_code');
    }

    protected function applyShippingQuote(string $service = 'ems', string $country = 'US'): TestResponse
    {
        return $this->graphQL('
            mutation applyShippingQuote($service: String!, $destination: ShippingDestinationInput!) {
                applyShippingQuote(provider: "inposdom", service: $service, destination: $destination) {
                    shipping {
                        name
                        value
                        attributes
                    }
                }
            }
        ', [
            'service' => $service,
            'destination' => ['country' => $country],
        ], [], $this->cartHeaders());
    }

    protected function cartShipping(): ?array
    {
        return $this->graphQL('
            query {
                cart {
                    shipping {
                        name
                        value
                        attributes
                    }
                }
            }
        ', [], [], $this->cartHeaders())->json('data.cart.shipping');
    }

    protected function placeOrderFromCart(mixed $metadata = null): TestResponse
    {
        return $this->graphQL('
            mutation createOrderFromCart($input: OrderCartInput!) {
                createOrderFromCart(input: $input) {
                    order {
                        id
                    }
                }
            }
        ', [
            'input' => [
                'cartId' => 0,
                'customer' => [
                    'email' => fake()->email(),
                ],
                'currency' => 'USD',
                'shipping_address' => [
                    'address' => fake()->address(),
                    'address_2' => fake()->postcode(),
                    'city' => fake()->city(),
                    'state' => fake()->state(),
                ],
                'order_type' => 'order',
            ] + (($metadata !== null) ? ['metadata' => $metadata] : []),
        ], [], $this->cartHeaders());
    }

    protected function orderFromResponse(TestResponse $response): Order
    {
        return Order::findOrFail($response->json('data.createOrderFromCart.order.id'));
    }
}
