<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\EchoPay\Enums\ConfigurationEnum;
use Kanvas\Connectors\EchoPay\Handlers\EchoPayHandler;
use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\GraphQL\Souk\Traits\PaymentCases;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use HasIntegrationCompany;
    use PaymentCases;

    protected Users $user;
    protected Companies $company;
    protected ?Apps $apps;

    public function setUp(): void
    {
        parent::setUp();
        $this->user = Users::factory()->create();
        $this->company = Companies::factory()->create([
            'users_id' => $this->user->id,
        ]);
        $this->apps = app(Apps::class);

        $this->apps->set(ConfigurationEnum::CLIENT_ID->value, env('TEST_ECHO_PAY_CLIENT_ID'));
        $this->apps->set(ConfigurationEnum::SECRET->value, env('TEST_ECHO_PAY_SECRET'));
        $this->apps->set(ConfigurationEnum::APP_TOKEN->value, env('TEST_ECHO_PAY_APP_TOKEN'));
        $this->apps->set(ConfigurationEnum::MERCHANT_ID->value, env('TEST_ECHO_PAY_MERCHANT_ID'));
        $this->apps->set(ConfigurationEnum::MERCHANT_KEY->value, env('TEST_ECHO_PAY_MERCHANT_KEY'));
        $this->apps->set(ConfigurationEnum::MERCHANT_SECRET->value, env('TEST_ECHO_PAY_MERCHANT_SECRET'));

        $this->apps->setAppCompany($this->company);

        $this->setIntegration(
            $this->apps,
            IntegrationsEnum::ECHO_PAY,
            EchoPayHandler::class,
            $this->company,
            $this->user
        );
    }

    public function testCreatePaymentMethod()
    {
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
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $response->assertSuccessful();
    }

    public function testCreatePaymentMethodWithFirstnameAndLastname()
    {
        // Perform GraphQL mutation to create a payment method
        $response = $this->graphQL('
            mutation createPaymentMethod($input: PaymentMethodInput!) {
                createPaymentMethod(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                ...$this->getCardData(),
                'firstname' => 'Juan',
                'lastname' => 'Pérez',
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $paymentMethodData = $response->json('data.createPaymentMethod');
        $paymentMethod = PaymentMethods::find($paymentMethodData['id']);

        $response->assertSuccessful();
        $this->assertEquals('Juan', $paymentMethod->getMetadata('firstname'));
        $this->assertEquals('Pérez', $paymentMethod->getMetadata('lastname'));
    }

    public function testListPaymentMethods()
    {
        try {
            $this->addPaymentMethod($this->company, $this->getCardData());
        } catch (Exception $e) {
            $this->fail('Error adding payment method: ' . $e->getMessage());
        }

        // Get the payment methods
        $response = $this->graphQL('
            query paymentMethods {
                paymentMethods {
                    id
                }
            }
        ', [], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $response->assertSuccessful();
    }

    public function testDeletePaymentMethod()
    {
        $paymentMethod = $this->addPaymentMethod($this->company, $this->getCardData());

        $response = $this->graphQL('
            mutation deletePaymentMethod($id: ID!) {
                deletePaymentMethod(id: $id)
            }
        ', [
            'id' => $paymentMethod['id'],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $response->assertSuccessful();
    }

    public function testUpdatePaymentMethod()
    {
        $paymentMethod = $this->addPaymentMethod($this->company, $this->getCardData());

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
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $response->assertSuccessful();
    }

    public function testCreatePaymentMethodVisa()
    {
        // Perform GraphQL mutation to create a payment method
        $response = $this->graphQL('
            mutation createPaymentMethod($input: PaymentMethodInput!) {
                createPaymentMethod(input: $input) {
                    payment_methods_brand
                }
            }
        ', [
            'input' => [
                ...$this->getCardData(),
                'processor' => null,
                'brand' => null,
                'number' => '4111111111111111',
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $response->assertSuccessful();
        $response->assertJson([
            'data' => [
                'createPaymentMethod' => [
                    'payment_methods_brand' => 'visa',
                ],
            ],
        ]);
    }

    public function testCreatePaymentMethodMastercard()
    {
        // Perform GraphQL mutation to create a payment method
        $response = $this->graphQL('
            mutation createPaymentMethod($input: PaymentMethodInput!) {
                createPaymentMethod(input: $input) {
                    payment_methods_brand
                }
            }
        ', [
            'input' => [
                ...$this->getCardData(),
                'processor' => null,
                'brand' => null,
                'number' => '5555555555554444',
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $response->assertSuccessful();
        $response->assertJson([
            'data' => [
                'createPaymentMethod' => [
                    'payment_methods_brand' => 'mastercard',
                ],
            ],
        ]);
    }

    public function testCreatePaymentMethodAmex()
    {
        // Perform GraphQL mutation to create a payment method
        $response = $this->graphQL('
            mutation createPaymentMethod($input: PaymentMethodInput!) {
                createPaymentMethod(input: $input) {
                    payment_methods_brand
                }
            }
        ', [
            'input' => [
                ...$this->getCardData(),
                'processor' => null,
                'brand' => null,
                'number' => '378282246310005',
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $response->assertSuccessful();
        $response->assertJson([
            'data' => [
                'createPaymentMethod' => [
                    'payment_methods_brand' => 'amex',
                ],
            ],
        ]);
    }
}
