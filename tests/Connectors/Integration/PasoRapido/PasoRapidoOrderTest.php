<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\PasoRapido;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum as EnumsCustomFieldEnum;
use Kanvas\Connectors\PasoRapido\Actions\CreatePasoRapidoOrderAction;
use Kanvas\Connectors\PasoRapido\DataTransferObject\InvoiceDetails;
use Kanvas\Connectors\PasoRapido\DataTransferObject\PaymentConfirmData;
use Kanvas\Connectors\PasoRapido\DataTransferObject\PaymentConfirmResponse;
use Kanvas\Connectors\PasoRapido\Enums\ConfigurationEnum;
use Kanvas\Connectors\PasoRapido\Enums\CustomFieldEnum;
use Kanvas\Connectors\PasoRapido\Handlers\PasoRapidoHandler;
use Kanvas\Connectors\PasoRapido\Services\PasoRapidoService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Mockery;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

final class PasoRapidoOrderTest extends TestCase
{
    use HasIntegrationCompany;
    use InventoryCases;

    public function testCreatePasoRapidoOrder(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();
        $region = Regions::getDefault($company ?? $company, $app);

        $app->set(ConfigurationEnum::CLIENT_ID->value, env('TEST_PASO_RAPIDO_CLIENT_ID'));
        $app->set(ConfigurationEnum::SECRET->value, env('TEST_PASO_RAPIDO_SECRET'));

        $this->setIntegration(
            $app,
            IntegrationsEnum::PASO_RAPIDO,
            PasoRapidoHandler::class,
            $company,
            $user
        );

        $warehouseResponse = $this->createWarehouses((string) $region->getId())->json()['data']['createWarehouse'];
        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'slots',
                'value' => 100,
            ],
        ])->json()['data']['createProduct'];

        $warehouseData = [
            'id' => $warehouseResponse['id'],
        ];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: $warehouseData,
            attributes: [
                [
                    'name' => 'timezone',
                    'value' => 'America/New_York',
                ],
            ]
        )->json()['data']['createVariant'];

        $channelResponse = $this->createChannel()->json()['data']['createChannel'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $channelResponse['id'],
            warehouseData: $warehouseData
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $warehouseResponse['id'],
            amount: 100
        );

        $transactionId = '7478925724996114' . rand(100000, 999999);

        $data = [
            'email' => fake()->email(),
            'region_id' => $region->getId(),
            'metadata' => [
                'data' => [
                    'paso_rapido_tag' => '317169',
                    'payment_methods_id' => '91',
                    'payment_date' => now()->toDateTimeString(),
                ],
            ],
            'customer' => [
                'firstname' => fake()->firstName(),
                'lastname' => fake()->lastName(),
            ],
            'shipping_address' => [
                'address' => fake()->address(),
                'address_2' => fake()->postcode(),
                'city' => fake()->city(),
                'state' => fake()->state(),
            ],
            'items' => [
                [
                    'variant_id' => $variantResponse['id'],
                    'quantity' => 1,
                    'price' => 100,
                ],
            ],
        ];

        // Perform GraphQL mutation to create a draft order
        $response = $this->graphQL('
            mutation createDraftOrder($input: DraftOrderInput!) {
                createDraftOrder(input: $input) {
                    id
                }
            }
        ', [
            'input' => $data,
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
            'X-Kanvas-App' => $app->key,
        ]);

        $order = $response->json()['data']['createDraftOrder'];
        $order = Order::fromApp($app)->find($order['id']);

        $order->set(EnumsCustomFieldEnum::ECHO_PAY_PAYMENT_INTENT_ID->value, 'intentId:' . $transactionId);
        $order->set(CustomFieldEnum::PASO_RAPIDO_DNI->value, '1234567890');

        $createPasoRapidoOrderAction = new CreatePasoRapidoOrderAction($app, $order);
        $result = $createPasoRapidoOrderAction->execute();

        $order->refresh();
        $this->assertArrayHasKey('order', $result['data']);
        $this->assertArrayHasKey('tag', $result['data']);
        $this->assertNotNull($order->get(CustomFieldEnum::PASO_RAPIDO_PAYMENT_STATUS->value));
        $this->assertNotNull($order->get(CustomFieldEnum::PASO_RAPIDO_PAYMENT_RESPONSE->value));
    }

    public function testCreatePasoRapidoOrderSendsFiscalCreditWhenFlagInMetadata(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();
        $region = Regions::getDefault($company, $app);

        $this->setIntegration(
            $app,
            IntegrationsEnum::PASO_RAPIDO,
            PasoRapidoHandler::class,
            $company,
            $user
        );

        $warehouseResponse = $this->createWarehouses((string) $region->getId())->json()['data']['createWarehouse'];
        $productResponse = $this->createProduct(attributes: [
            ['name' => 'slots', 'value' => 100],
        ])->json()['data']['createProduct'];
        $warehouseData = ['id' => $warehouseResponse['id']];
        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: $warehouseData,
            attributes: [
                ['name' => 'timezone', 'value' => 'America/New_York'],
            ]
        )->json()['data']['createVariant'];
        $channelResponse = $this->createChannel()->json()['data']['createChannel'];
        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $channelResponse['id'],
            warehouseData: $warehouseData
        );
        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $warehouseResponse['id'],
            amount: 100
        );

        $rnc = '101010101';
        $transactionId = '7478925724996114' . rand(100000, 999999);

        $data = [
            'email' => fake()->email(),
            'region_id' => $region->getId(),
            'metadata' => [
                'data' => [
                    'paso_rapido_tag' => '317169',
                    'fiscal_credit' => true,
                    'rnc' => $rnc,
                    'payment_methods_id' => '91',
                    'payment_date' => now()->toDateTimeString(),
                ],
            ],
            'customer' => [
                'firstname' => fake()->firstName(),
                'lastname' => fake()->lastName(),
            ],
            'shipping_address' => [
                'address' => fake()->address(),
                'address_2' => fake()->postcode(),
                'city' => fake()->city(),
                'state' => fake()->state(),
            ],
            'items' => [
                [
                    'variant_id' => $variantResponse['id'],
                    'quantity' => 1,
                    'price' => 100,
                ],
            ],
        ];

        $response = $this->graphQL('
            mutation createDraftOrder($input: DraftOrderInput!) {
                createDraftOrder(input: $input) {
                    id
                }
            }
        ', [
            'input' => $data,
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
            'X-Kanvas-App' => $app->key,
        ]);

        $order = Order::fromApp($app)->find($response->json()['data']['createDraftOrder']['id']);
        $order->set(EnumsCustomFieldEnum::ECHO_PAY_PAYMENT_INTENT_ID->value, 'intentId:' . $transactionId);

        $capturedConfirmData = null;
        $serviceMock = Mockery::mock(PasoRapidoService::class);
        $serviceMock->shouldReceive('confirmPayment')
            ->once()
            ->with(Mockery::on(function (PaymentConfirmData $data) use (&$capturedConfirmData) {
                $capturedConfirmData = $data;

                return true;
            }))
            ->andReturn(new PaymentConfirmResponse(
                message: 'OK',
                amount: 100.0,
                order: 1,
                tag: '317169',
                account: 1,
                creditDate: now()->toDateTimeString(),
                invoiceDetails: new InvoiceDetails(
                    commercialName: 'Test',
                    document: $rnc,
                    fiscalCredit: true,
                    invoice: 'INV-1',
                    pdf: 'http://example.com/inv.pdf',
                    reference: '317169',
                ),
            ));

        $result = new CreatePasoRapidoOrderAction($app, $order, $serviceMock)->execute();

        $this->assertSame('success', $result['status']);
        $this->assertInstanceOf(PaymentConfirmData::class, $capturedConfirmData);
        $this->assertTrue($capturedConfirmData->fiscalCredit);
        $this->assertSame($rnc, $capturedConfirmData->dni);

        $order->refresh();

        $this->assertSame('Test', $order->get(CustomFieldEnum::PASO_RAPIDO_INVOICE_COMMERCIAL_NAME->value));
        $this->assertEquals($rnc, $order->get(CustomFieldEnum::PASO_RAPIDO_INVOICE_DOCUMENT->value));
        $this->assertEquals('1', $order->get(CustomFieldEnum::PASO_RAPIDO_INVOICE_FISCAL_CREDIT->value));
        $this->assertSame('INV-1', $order->get(CustomFieldEnum::PASO_RAPIDO_INVOICE_NCF->value));
        $this->assertSame('http://example.com/inv.pdf', $order->get(CustomFieldEnum::PASO_RAPIDO_INVOICE_PDF->value));
        $this->assertEquals('317169', $order->get(CustomFieldEnum::PASO_RAPIDO_INVOICE_REFERENCE->value));

        $invoice = $order->metadata['data']['invoice'] ?? null;
        $this->assertIsArray($invoice);
        $this->assertSame('Test', $invoice['commercialName']);
        $this->assertSame($rnc, $invoice['document']);
        $this->assertTrue($invoice['fiscalCredit']);
        $this->assertSame('INV-1', $invoice['invoice']);
        $this->assertSame('http://example.com/inv.pdf', $invoice['pdf']);
        $this->assertSame('317169', $invoice['reference']);
    }

    public function testCreatePasoRapidoOrderFallsBackToDniCustomFieldWhenNoRnc(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();
        $region = Regions::getDefault($company, $app);

        $this->setIntegration(
            $app,
            IntegrationsEnum::PASO_RAPIDO,
            PasoRapidoHandler::class,
            $company,
            $user
        );

        $warehouseResponse = $this->createWarehouses((string) $region->getId())->json()['data']['createWarehouse'];
        $productResponse = $this->createProduct(attributes: [
            ['name' => 'slots', 'value' => 100],
        ])->json()['data']['createProduct'];
        $warehouseData = ['id' => $warehouseResponse['id']];
        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: $warehouseData,
            attributes: [
                ['name' => 'timezone', 'value' => 'America/New_York'],
            ]
        )->json()['data']['createVariant'];
        $channelResponse = $this->createChannel()->json()['data']['createChannel'];
        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $channelResponse['id'],
            warehouseData: $warehouseData
        );
        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $warehouseResponse['id'],
            amount: 100
        );

        $legacyDni = '40212345678';
        $transactionId = '7478925724996114' . rand(100000, 999999);

        $data = [
            'email' => fake()->email(),
            'region_id' => $region->getId(),
            'metadata' => [
                'data' => [
                    'paso_rapido_tag' => '317169',
                    'payment_methods_id' => '91',
                    'payment_date' => now()->toDateTimeString(),
                ],
            ],
            'customer' => [
                'firstname' => fake()->firstName(),
                'lastname' => fake()->lastName(),
            ],
            'shipping_address' => [
                'address' => fake()->address(),
                'address_2' => fake()->postcode(),
                'city' => fake()->city(),
                'state' => fake()->state(),
            ],
            'items' => [
                [
                    'variant_id' => $variantResponse['id'],
                    'quantity' => 1,
                    'price' => 100,
                ],
            ],
        ];

        $response = $this->graphQL('
            mutation createDraftOrder($input: DraftOrderInput!) {
                createDraftOrder(input: $input) {
                    id
                }
            }
        ', [
            'input' => $data,
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
            'X-Kanvas-App' => $app->key,
        ]);

        $order = Order::fromApp($app)->find($response->json()['data']['createDraftOrder']['id']);
        $order->set(EnumsCustomFieldEnum::ECHO_PAY_PAYMENT_INTENT_ID->value, 'intentId:' . $transactionId);
        $order->set(CustomFieldEnum::PASO_RAPIDO_DNI->value, $legacyDni);

        $capturedConfirmData = null;
        $serviceMock = Mockery::mock(PasoRapidoService::class);
        $serviceMock->shouldReceive('confirmPayment')
            ->once()
            ->with(Mockery::on(function (PaymentConfirmData $data) use (&$capturedConfirmData) {
                $capturedConfirmData = $data;

                return true;
            }))
            ->andReturn(new PaymentConfirmResponse(
                message: 'OK',
                amount: 100.0,
                order: 1,
                tag: '317169',
                account: 1,
                creditDate: now()->toDateTimeString(),
                invoiceDetails: new InvoiceDetails(
                    commercialName: 'Test',
                    document: '',
                    fiscalCredit: false,
                    invoice: '',
                    pdf: '',
                    reference: '317169',
                ),
            ));

        $result = new CreatePasoRapidoOrderAction($app, $order, $serviceMock)->execute();

        $this->assertSame('success', $result['status']);
        $this->assertInstanceOf(PaymentConfirmData::class, $capturedConfirmData);
        $this->assertFalse($capturedConfirmData->fiscalCredit);
        $this->assertSame($legacyDni, $capturedConfirmData->dni);
    }

    public function testCreatePasoRapidoOrderUsesRncOfCorporateProviderCompany(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        $corporateRnc = '130123456';
        $corporateCompany = Companies::factory()->create();
        $corporateCompany->set('is_corporate', true);
        $corporateCompany->set('rnc', $corporateRnc);

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();

        $order = Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($people->getId())
            ->create([
                'metadata' => [
                    'data' => [
                        'paso_rapido_tag' => '317169',
                        'rnc' => '99999999',
                    ],
                ],
            ]);

        $order->providerCompanies()->attach($corporateCompany->getId());
        $order->set(EnumsCustomFieldEnum::ECHO_PAY_PAYMENT_INTENT_ID->value, 'intentId:7478925724996114' . rand(100000, 999999));
        $order->set(CustomFieldEnum::PASO_RAPIDO_DNI->value, '40212345678');

        $capturedConfirmData = null;
        $serviceMock = Mockery::mock(PasoRapidoService::class);
        $serviceMock->shouldReceive('confirmPayment')
            ->once()
            ->with(Mockery::on(function (PaymentConfirmData $data) use (&$capturedConfirmData) {
                $capturedConfirmData = $data;

                return true;
            }))
            ->andReturn(new PaymentConfirmResponse(
                message: 'OK',
                amount: 100.0,
                order: 1,
                tag: '317169',
                account: 1,
                creditDate: now()->toDateTimeString(),
                invoiceDetails: new InvoiceDetails(
                    commercialName: 'Test',
                    document: $corporateRnc,
                    fiscalCredit: true,
                    invoice: 'INV-1',
                    pdf: 'http://example.com/inv.pdf',
                    reference: '317169',
                ),
            ));

        $result = new CreatePasoRapidoOrderAction($app, $order, $serviceMock)->execute();

        $this->assertSame('success', $result['status']);
        $this->assertSame($corporateRnc, $capturedConfirmData->dni);
        $this->assertTrue($capturedConfirmData->fiscalCredit);
    }

    public function testCreatePasoRapidoOrderFailsWhenFiscalCreditHasNoDocument(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();

        $order = Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($people->getId())
            ->create([
                'metadata' => [
                    'data' => [
                        'paso_rapido_tag' => '317169',
                        'fiscal_credit' => true,
                    ],
                ],
            ]);

        $order->set(EnumsCustomFieldEnum::ECHO_PAY_PAYMENT_INTENT_ID->value, 'intentId:7478925724996114' . rand(100000, 999999));

        $serviceMock = Mockery::mock(PasoRapidoService::class);
        $serviceMock->shouldNotReceive('confirmPayment');

        $result = new CreatePasoRapidoOrderAction($app, $order, $serviceMock)->execute();

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('RNC', (string) $result['message']);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $order->fresh()->get(CustomFieldEnum::PASO_RAPIDO_PAYMENT_STATUS->value));
        $this->assertSame('0', (string) $order->fresh()->get(EnumsCustomFieldEnum::ECHO_PAY_SHOULD_CAPTURE->value));
    }

    public function testCreatePasoRapidoOrderRejectsBulkRechargeOrders(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();

        $order = Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($people->getId())
            ->create([
                'metadata' => [
                    'data' => ['is_bulk_recharge' => true],
                ],
            ]);

        $result = new CreatePasoRapidoOrderAction($app, $order)->execute();

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('wallet', strtolower((string) $result['message']));
        $this->assertSame('0', (string) $order->fresh()->get(EnumsCustomFieldEnum::ECHO_PAY_SHOULD_CAPTURE->value));
    }
}
