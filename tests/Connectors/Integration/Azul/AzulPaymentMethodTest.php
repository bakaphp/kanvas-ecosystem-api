<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Azul;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Azul\Enums\ConfigurationEnum;
use Kanvas\Connectors\Azul\Enums\CustomFieldEnum;
use Kanvas\Payments\Models\PaymentMethods;

class AzulPaymentMethodTest extends AzulBase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app         = app(Apps::class);
        $credentials = $this->getCredentials();

        $app->set(ConfigurationEnum::AZUL_AUTH1->value, $credentials['auth1']);
        $app->set(ConfigurationEnum::AZUL_AUTH2->value, $credentials['auth2']);
        $app->set(ConfigurationEnum::AZUL_STORE->value, $credentials['store']);
        $app->set(ConfigurationEnum::AZUL_CHANNEL->value, $credentials['channel']);
        $app->set(ConfigurationEnum::AZUL_CERT_PATH->value, $credentials['cert_path']);
        $app->set(ConfigurationEnum::AZUL_KEY_PATH->value, $credentials['key_path']);
    }

    protected function getAzulCardData(): array
    {
        return [
            'number'          => '4260550061845872',
            'expiration_date' => '12/28',
            'cvv'             => '872',
            'processor'       => 'azul',
        ];
    }

    public function testCreatePaymentMethodWithAzul(): void
    {
        $company = auth()->user()->getCurrentCompany();

        $response = $this->graphQL('
            mutation createPaymentMethod($input: PaymentMethodInput!) {
                createPaymentMethod(input: $input) {
                    id
                    payment_ending_numbers
                    payment_methods_brand
                    processor
                    metadata
                }
            }
        ', [
            'input' => $this->getAzulCardData(),
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ]);

        $response->assertSuccessful();
        $this->assertNull($response->json('errors'), 'GraphQL errors: ' . json_encode($response->json('errors')));

        $data = $response->json('data.createPaymentMethod');
        $this->assertEquals('azul', $data['processor']);
        $this->assertEquals('visa', $data['payment_methods_brand']);
        $this->assertEquals('5872', $data['payment_ending_numbers']);

        $paymentMethod = PaymentMethods::find($data['id']);
        $this->assertNotEmpty($paymentMethod->stripe_card_id, 'DataVault token should be stored');
        $this->assertNotEmpty(
            $paymentMethod->getMetadata(CustomFieldEnum::AZUL_DATA_VAULT_TOKEN->value),
            'azul_data_vault_token should be in metadata'
        );
    }

    public function testDeletePaymentMethodWithAzul(): void
    {
        $company = auth()->user()->getCurrentCompany();

        $createResponse = $this->graphQL('
            mutation createPaymentMethod($input: PaymentMethodInput!) {
                createPaymentMethod(input: $input) {
                    id
                }
            }
        ', [
            'input' => $this->getAzulCardData(),
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ]);

        $createResponse->assertSuccessful();
        $id = $createResponse->json('data.createPaymentMethod.id');

        $deleteResponse = $this->graphQL('
            mutation deletePaymentMethod($id: ID!) {
                deletePaymentMethod(id: $id)
            }
        ', [
            'id' => $id,
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ]);

        $deleteResponse->assertSuccessful();
        $deleteResponse->assertJson(['data' => ['deletePaymentMethod' => true]]);
    }

    public function testListPaymentMethodsAfterCreate(): void
    {
        $company = auth()->user()->getCurrentCompany();

        $this->graphQL('
            mutation createPaymentMethod($input: PaymentMethodInput!) {
                createPaymentMethod(input: $input) { id }
            }
        ', [
            'input' => $this->getAzulCardData(),
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ])->assertSuccessful();

        $response = $this->graphQL('
            query paymentMethods {
                paymentMethods {
                    data {
                        id
                        processor
                        payment_ending_numbers
                    }
                }
            }
        ', [], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ]);

        $response->assertSuccessful();

        $methods    = $response->json('data.paymentMethods.data');
        $azulMethod = collect($methods)->firstWhere('processor', 'azul');
        $this->assertNotNull($azulMethod);
    }
}
