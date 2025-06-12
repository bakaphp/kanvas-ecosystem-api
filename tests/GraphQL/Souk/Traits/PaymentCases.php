<?php

namespace Tests\GraphQL\Souk\Traits;

use Exception;
use Kanvas\Companies\Models\Companies;

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
}
