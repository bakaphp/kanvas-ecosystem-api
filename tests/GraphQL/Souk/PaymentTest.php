<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\EchoPay\Enums\ConfigurationEnum;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use InventoryCases;

    protected $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = app(Apps::class);

        $this->app->set(ConfigurationEnum::CLIENT_ID->value, env('TEST_ECHO_PAY_CLIENT_ID'));
        $this->app->set(ConfigurationEnum::SECRET->value, env('TEST_ECHO_PAY_SECRET'));
        $this->app->set(ConfigurationEnum::MERCHANT_ID->value, env('TEST_ECHO_PAY_MERCHANT_ID'));
        $this->app->set(ConfigurationEnum::MERCHANT_KEY->value, env('TEST_ECHO_PAY_MERCHANT_KEY'));
    }

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

        print_r($response->json());

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
        $variantWarehouse = VariantsWarehouses::first();
        $region = $variantWarehouse->warehouse->region;
        $company = $region->company;
        $user = $company->user;

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
        $variantWarehouse = VariantsWarehouses::first();
        $region = $variantWarehouse->warehouse->region;
        $company = $region->company;
        $user = $company->user;

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
        $company = $user->getCurrentCompany();

        $paymentMethod = $this->addPaymentMethod($company, $this->getCardData());

        $response = $this->graphQL('
            mutation deletePaymentMethod($id: ID!) {
                deletePaymentMethod(id: $id)
            }
        ', [
            'id' => $paymentMethod['id'],
        ], [], [
            'X-Kanvas-App' => $this->app->uuid,
            'X-Kanvas-Location' => $company->branch->uuid,
        ]);

        $response->assertSuccessful();
    }
}
