<?php

namespace Tests\GraphQL\Souk\Traits;

use Baka\Contracts\AppInterface;
use Exception;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Services\StripePaymentService;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Stripe\PaymentIntent;

trait PaymentCases
{
    public function addPaymentMethod(Companies $company, array $data): array
    {
        $response = $this->graphQL('
        mutation createPaymentMethod($input: PaymentMethodInput!) {
            createPaymentMethod(input: $input) {
                id
                }
            }
        ', [
            'input' => $data,
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ]);

        $paymentMethod = $response->json('data.createPaymentMethod');

        if (! $paymentMethod) {
            throw new Exception('Error adding payment method: ' . json_encode($response->json()));
        }

        return $paymentMethod;
    }

    public function getCardData(): array
    {
        return [
            'number' => '4111111111111111',
            'processor' => 'portal',
            'brand' => 'visa',
            'expiration_date' => '2030-12',
            'metadata' => [],
            'address' => 'Calle Duarte #45',
            'city' => 'Santo Domingo',
            'state' => 'Distrito Nacional',
            'zip_code' => '10101',
            'country' => 'DO',
            'phone' => '8095551234',
        ];
    }

    public function createTestPaymentIntent(array $order, AppInterface $app): PaymentIntent
    {
        $stripePaymentService = (new StripePaymentService($app))->getClient();

        $item = $order['variant_id'];
        if (empty($order['amount'])) {
            $order['amount'] = $order['price'] * $order['quantity'];
        }
        $params = [
            'amount' => (int) ($order['amount'] * 100),
            'currency' => 'usd',
            'metadata' => [
                'test_mode' => 'true',
            ],
            'payment_method' => 'pm_card_visa',
            'confirm' => true,
            'description' => "Test payment for order #{$item}",
            'payment_method_types' => ['card'],
        ];

        return $stripePaymentService->paymentIntents->create($params);
    }

    public function createTestFailingPaymentIntent(array $order, AppInterface $app): PaymentIntent
    {
        $stripePaymentService = (new StripePaymentService($app))->getClient();

        $item = $order['variant_id'];
        if (empty($order['amount'])) {
            $order['amount'] = $order['price'] * $order['quantity'];
        }
        $params = [
            'amount' => (int) ($order['amount'] * 100),
            'currency' => 'usd',
            'metadata' => [
                'test_type' => 'failure',
                'test_mode' => 'true',
            ],
            'description' => "Test payment for order #{$item}",
            'automatic_payment_methods' => [
            'enabled' => true,
            'allow_redirects' => 'never',
        ],
        ];

        return $stripePaymentService->paymentIntents->create($params);
    }

    public function setAllowNoPaymentStatus(bool $status, AppInterface $app): void
    {
        $app->set(ConfigurationEnum::ALLOW_NO_PAYMENT_ORDER->value, $status);
    }
}
