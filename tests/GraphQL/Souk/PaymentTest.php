<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Tests\TestCase;

class PaymentTest extends TestCase
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

        return $response->json('data.createPaymentMethod');
    }

    public function getCardData(): array
    {
        return [
            "number" => "4111111111111111",
            "processor" => "portal",
            "brand" => "visa",
            "expiration_date" => "2030-12",
            "metadata" => [],
            "address" => "Calle Duarte #45",
            "city" => "Santo Domingo",
            "state" => "Distrito Nacional",
            "zip_code" => "10101",
            "country" => "DO",
            "phone" => "8095551234"
        ];
    }

    public function testCreatePaymentMethod()
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Perform GraphQL mutation to create a payment method
        $response = $this->graphQL('
            mutation createPaymentMethod($input: PaymentMethodInput!) {
                createPaymentMethod(input: $input) {
                    id
                }
            }
        ', [
            'input' => $this->getCardData(),
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ]);

        $response->assertSuccessful();
    }

    public function testListPaymentMethods()
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $this->addPaymentMethod($company, $this->getCardData());

        // Get the payment methods
        $response = $this->graphQL('
            query paymentMethods {
                paymentMethods {
                    id
                }
            }
        ', [], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ]);

        $response->assertSuccessful();
    }

    public function testDeletePaymentMethod()
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $paymentMethod = $this->addPaymentMethod($company, $this->getCardData());

        $response = $this->graphQL('
            mutation deletePaymentMethod($id: ID!) {
                deletePaymentMethod(id: $id)
            }
        ', [
            'id' => $paymentMethod['id'],
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ]);

        $response->assertSuccessful();
    }

    public function testUpdatePaymentMethod()
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $paymentMethod = $this->addPaymentMethod($company, $this->getCardData());

        $response = $this->graphQL('
            mutation updatePaymentMethod($id: ID!, $input: PaymentMethodInput!) {
                updatePaymentMethod(id: $id, input: $input) {
                    id
                }
            }
        ', [
            'id' => $paymentMethod['id'],
            'input' => $this->getCardData(),
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ]);

        $response->assertSuccessful();
    }
}
