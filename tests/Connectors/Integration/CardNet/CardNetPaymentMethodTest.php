<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\CardNet;

use Kanvas\Connectors\CardNet\Enums\CustomFieldEnum;
use Kanvas\Payments\Models\PaymentMethods;
use Tests\TestCase;

final class CardNetPaymentMethodTest extends TestCase
{
    use HasCardNetConfiguration;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('CardNet integration tests are skipped in CI');
        }

        $privateKey = env('CARDNET_PRIVATE_KEY');

        if (empty($privateKey)) {
            $this->markTestSkipped('CardNet credentials not configured (CARDNET_PRIVATE_KEY)');
        }

        $this->setUpCardNetConfiguration();
    }

    protected function getCardNetCardData(): array
    {
        return [
            'number' => env('CARDNET_TEST_PAN'),
            'expiration_date' => env('CARDNET_TEST_EXPIRATION'),
            'cvv' => env('CARDNET_TEST_CVV'),
            'processor' => 'cardnet',
            'firstname' => env('CARDNET_TEST_TITULAR', 'Test User'),
            'lastname' => '',
        ];
    }

    public function testCreatePaymentMethodWithCardNet(): void
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
            'input' => $this->getCardNetCardData(),
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ]);

        $response->assertSuccessful();
        $this->assertNull($response->json('errors'), 'GraphQL errors: ' . json_encode($response->json('errors')));

        $data = $response->json('data.createPaymentMethod');
        $this->assertEquals('cardnet', $data['processor']);
        $this->assertEquals('0050', $data['payment_ending_numbers']);

        $paymentMethod = PaymentMethods::find($data['id']);
        $this->assertNotEmpty($paymentMethod->stripe_card_id, 'CardNet token should be stored');
        $this->assertNotEmpty(
            $paymentMethod->getMetadata(CustomFieldEnum::CUSTOMER_ID->value),
            'CardNet customer_id should be in metadata',
        );
    }

    public function testDeletePaymentMethodWithCardNet(): void
    {
        $company = auth()->user()->getCurrentCompany();

        $createResponse = $this->graphQL('
            mutation createPaymentMethod($input: PaymentMethodInput!) {
                createPaymentMethod(input: $input) {
                    id
                }
            }
        ', [
            'input' => $this->getCardNetCardData(),
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
            'input' => $this->getCardNetCardData(),
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

        $methods = $response->json('data.paymentMethods.data');
        $cardnetMethod = collect($methods)->firstWhere('processor', 'cardnet');
        $this->assertNotNull($cardnetMethod);
    }
}
