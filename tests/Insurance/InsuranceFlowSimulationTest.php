<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\UniversalSeguros\DataTransferObject\QuoteRequest;
use Kanvas\Connectors\UniversalSeguros\Enums\ConfigurationEnum;
use Kanvas\Connectors\UniversalSeguros\Handlers\UniversalSegurosHandler;
use Kanvas\Connectors\UniversalSeguros\Providers\UniversalSegurosProvider;
use Kanvas\Connectors\UniversalSeguros\Services\UniversalSegurosService;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Insurance\Activities\CancelInsuranceOrderActivity;
use Kanvas\Insurance\Activities\AttachInsuranceQuoteActivity;
use Kanvas\Insurance\Activities\EmitInsurancePolicyActivity;
use Kanvas\Insurance\Activities\InvoiceInsurancePolicyActivity;
use Kanvas\Insurance\Activities\ReportInsurancePaymentActivity;
use Kanvas\Insurance\Activities\SyncInsurancePolicyActivity;
use Kanvas\Insurance\Activities\UploadInsuranceDocumentsActivity;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Enums\InsuranceStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Contracts\PaymentProcessorInterface;
use Kanvas\Souk\Payments\DataTransferObject\CaptureResult;
use Kanvas\Souk\Payments\DataTransferObject\VoidResult;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Baka\Http\SafeUrlFetcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\Actions\SyncInsuranceProductsAction;
use Kanvas\Insurance\DataTransferObject\PaymentReportResult;
use Kanvas\Insurance\Services\InsuranceQuoteBinding;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Throwable;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\Insurance\Concerns\BuildsSearchCatalog;
use Tests\TestCase;

/**
 * Runs the whole insurance chain with the real activities, a real Order and a
 * real database, faking only the two things that leave the building: Universal's
 * HTTP service and the card processor. It is the closest thing to the browser
 * run that does not need Universal QA credentials.
 */
class InsuranceFlowSimulationTest extends TestCase
{
    use BuildsSearchCatalog;
    use DatabaseTransactions;
    use HasIntegrationCompany;

    protected $connectionsToTransact = [null, 'commerce', 'crm', 'inventory', 'ecosystem', 'social'];

    private const QUOTE = 'COT-777';
    private const POLICY = 'A-PA-0000001392';

    private const CUSTOMER = [
        'first_name' => 'Ana',
        'last_name' => 'Perez',
        'email' => 'ana@example.com',
        'phone' => '8095550100',
        'birth_date' => '1990-05-17',
        'sex' => 'female',
        'marital_status' => 'single',
        'document_type' => 'national_id',
        'document_number' => '00100000001',
        'nationality' => 'Dominicana',
        'occupation' => 'Ingeniera',
        'country_of_residence' => 'Republica Dominicana',
    ];

    private Apps $kanvasApp;

    private Users $kanvasUser;

    /** @var array<string, list<mixed>> */
    private array $calls = [];

    private MockInterface $service;

    private array $failing = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $this->kanvasUser = $user;

        $this->setIntegration(
            $this->kanvasApp,
            IntegrationsEnum::UNIVERSAL_SEGUROS,
            UniversalSegurosHandler::class,
            $user->getCurrentCompany(),
            $user,
        );
    }

    private function fakeUniversal(bool $emitRefused = false, bool $policyReady = true): void
    {
        $record = function (string $name, mixed $value = null) {
            $this->calls[$name][] = $value;
        };
        $quote = [
            'numeroCotizacion' => self::QUOTE,
            'data' => [
                'producto' => 'A-PA',
                'vehiculo' => [
                    'idModelo' => 77,
                    'anio' => 2022,
                    'esCeroKm' => false,
                    'combustible' => 'Gasolina / Diesel',
                    'cumpleRequisitos' => true,
                    'esUsoParticularNoDeportivoNoPublico' => true,
                    'valor' => 1000000,
                ],
                'terminos' => ['prima' => 5239.67, 'impuesto' => 838.33, 'totalCobro' => 6078],
            ],
        ];

        $this->service = Mockery::mock(UniversalSegurosService::class);
        $this->service->shouldReceive('getQuote')->andReturnUsing(function () use ($record, $quote) {
            $record('getQuote');

            return $quote;
        });
        $this->service->shouldReceive('updateQuote')->andReturnUsing(function (string $number, QuoteRequest $request) use ($record, $quote) {
            $record('updateQuote', $request->toArray());

            return $quote;
        });
        $this->service->shouldReceive('uploadDocument')->andReturnUsing(function ($reference, $transaction, $operation, $path) use ($record) {
            $record('uploadDocument', [$transaction->value, $operation->value, basename($path), file_exists($path)]);

            return [];
        });
        $this->service->shouldReceive('assignPaymentInformation')->andReturnUsing(function ($payment) use ($record) {
            $record('assignPaymentInformation', $payment->toArray());

            if (isset($this->failing['assignPaymentInformation'])) {
                throw $this->failing['assignPaymentInformation'];
            }

            return [];
        });
        $this->service->shouldReceive('emit')->andReturnUsing(function () use ($record, $emitRefused) {
            $record('emit');

            if (isset($this->failing['emit'])) {
                throw $this->failing['emit'];
            }

            if ($emitRefused) {
                throw new \Kanvas\Exceptions\ValidationException('Chasis no autorizado para emision');
            }

            return [];
        });
        $this->service->shouldReceive('getPolicy')->andReturnUsing(function () use ($policyReady) {
            return $policyReady ? ['numeroPoliza' => self::POLICY] : [];
        });
        $this->service->shouldReceive('invoicePolicy')->andReturnUsing(function ($payment) use ($record) {
            $record('invoicePolicy', $payment->toArray());

            return [];
        });

        $service = $this->service;
        app()->bind('insurance_provider.universal_seguros', fn ($app, array $params) => new UniversalSegurosProvider(
            app: $params['app'],
            company: $params['company'],
            service: $service,
        ));
    }

    private function fakeProcessor(): void
    {
        $processor = Mockery::mock(PaymentProcessorInterface::class);
        $processor->shouldReceive('capture')->andReturnUsing(function (Payments $payment) {
            $this->calls['capture'][] = $payment->getId();
            $payment->update(['status' => PaymentStatusEnum::PAID->value]);

            return new CaptureResult(true, 'captured', 'tx');
        });
        $processor->shouldReceive('void')->andReturnUsing(function (Payments $payment) {
            $this->calls['void'][] = $payment->getId();
            $payment->update(['status' => PaymentStatusEnum::CANCELLED->value]);

            return new VoidResult(true, 'voided', 'tx');
        });

        app()->bind('payment_processor.azul', fn () => $processor);
    }

    private function createOrder(): Order
    {
        $company = $this->kanvasUser->getCurrentCompany();
        $people = People::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($company->getId())
            ->withUserId($this->kanvasUser->getId())
            ->create();

        $order = Order::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($company->getId())
            ->withUserId($this->kanvasUser->getId())
            ->withPeopleId($people->getId())
            ->create([
                'currency' => 'DOP',
                'total_gross_amount' => 6078,
                'total_net_amount' => 6078,
                'metadata' => [
                    'insurance' => [
                        'provider' => UniversalSegurosProvider::NAME,
                        'product' => 'A-PA',
                        'quote_number' => self::QUOTE,
                        'payload' => ['tipo' => 'APA', 'terminos' => [
                            'seguroLey' => 'Auto Exceso',
                            'asistenciaVehicular' => false,
                            'autoSustituto' => 'No',
                            'fraccionamientoPago' => 'M',
                            'formaPago' => 't/c',
                        ]],
                        'cliente' => ['nombre' => 'Ana', 'apellido' => 'Perez', 'numeroDocumento' => '00100000001'],
                    ],
                ],
            ]);
        $order->setOrderType('movipass');

        return $order;
    }

    private function holdPayment(Order $order): Payments
    {
        return $order->payments()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $order->companies_id,
            'users_id' => $this->kanvasUser->getId(),
            'amount' => 6078,
            'payment_date' => date('Y-m-d'),
            'concept' => 'insurance',
            'currency' => 'DOP',
            'status' => PaymentStatusEnum::AUTHORIZED->value,
            'payment_method' => 'card',
            'processor' => 'azul',
            'payment_intent_id' => '44859568',
            'authorization_code' => 'OK7396',
            'metadata' => ['data' => [
                'iso_code' => '00',
                'response_code' => 'ISO8583',
                'response_message' => 'APROBADA',
                'masked_card_number' => '540000******1732',
                'datetime' => date('YmdHis'),
            ]],
        ]);
    }

    private function runActivity(string $class, Order $order): array
    {
        $activity = new $class(0, now()->toDateTimeString(), StoredWorkflow::make(), []);

        return $activity->execute($order->fresh(), $this->kanvasApp, []);
    }

    private function insuranceStatus(Order $order): ?string
    {
        return $order->fresh()->get(InsuranceCustomFieldEnum::STATUS->value);
    }

    public function testTheHappyPathFromQuoteToInvoicedPolicy(): void
    {
        $this->fakeUniversal();
        $this->fakeProcessor();
        $order = $this->createOrder();

        $attach = $this->runActivity(AttachInsuranceQuoteActivity::class, $order);
        $this->assertSame('success', $attach['status'], json_encode($attach));
        $this->assertSame(InsuranceStatusEnum::QUOTED->value, $this->insuranceStatus($order));
        $this->assertSame('Ana', $this->calls['updateQuote'][0]['data']['cliente']['nombre']);
        $this->assertEquals(6078.0, (float) $order->fresh()->get(InsuranceCustomFieldEnum::TOTAL->value));

        $payment = $this->holdPayment($order);

        $report = $this->runActivity(ReportInsurancePaymentActivity::class, $order);
        $this->assertSame('success', $report['status'], json_encode($report));
        $this->assertSame(InsuranceStatusEnum::PAID->value, $this->insuranceStatus($order));
        $this->assertSame(6078.0, (float) $this->calls['assignPaymentInformation'][0]['amount']);
        $this->assertSame('1732', $this->calls['assignPaymentInformation'][0]['cardLast4Digits']);
        $this->assertSame('COT-' . self::QUOTE, $this->calls['assignPaymentInformation'][0]['customOrderId']);

        $emitWithoutDocuments = $this->runActivity(EmitInsurancePolicyActivity::class, $order);
        $this->assertStringContainsString('Inspection documents are required', $emitWithoutDocuments['message']);
        $this->assertArrayNotHasKey('emit', $this->calls);

        $this->attachDocument($order, 'registration');
        $upload = $this->runActivity(UploadInsuranceDocumentsActivity::class, $order);
        $this->assertSame('success', $upload['status'], json_encode($upload));
        $this->assertSame(['Matricula', 'Cotizacion'], array_slice($this->calls['uploadDocument'][0], 0, 2));
        $this->assertTrue($this->calls['uploadDocument'][0][3]);

        $this->runActivity(UploadInsuranceDocumentsActivity::class, $order);
        $this->assertCount(1, $this->calls['uploadDocument'], 'an already-sent document must not be sent again');

        $emit = $this->runActivity(EmitInsurancePolicyActivity::class, $order);
        $this->assertSame('success', $emit['status'], json_encode($emit));
        $this->assertSame(self::POLICY, $emit['policyNumber']);
        $this->assertTrue($emit['paymentCaptured']);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->fresh()->status);
        $this->assertSame(InsuranceStatusEnum::POLICY_ACTIVE->value, $this->insuranceStatus($order));

        $invoice = $this->runActivity(InvoiceInsurancePolicyActivity::class, $order);
        $this->assertSame('success', $invoice['status'], json_encode($invoice));
        $this->assertSame(self::POLICY, $this->calls['invoicePolicy'][0]['noPoliza']);

        $again = $this->runActivity(InvoiceInsurancePolicyActivity::class, $order);
        $this->assertSame('skipped', $again['status']);
        $this->assertCount(1, $this->calls['invoicePolicy']);
    }

    public function testARefusedEmissionFailsTheOrderAndReleasesTheHold(): void
    {
        $this->fakeUniversal(emitRefused: true);
        $this->fakeProcessor();
        $order = $this->createOrder();
        $this->runActivity(AttachInsuranceQuoteActivity::class, $order);
        $payment = $this->holdPayment($order);
        $this->runActivity(ReportInsurancePaymentActivity::class, $order);
        $this->attachDocument($order, 'registration');
        $this->runActivity(UploadInsuranceDocumentsActivity::class, $order);

        $result = $this->runActivity(EmitInsurancePolicyActivity::class, $order);

        $this->assertStringContainsString('Chasis no autorizado', $result['message']);
        $this->assertSame(InsuranceStatusEnum::FAILED->value, $this->insuranceStatus($order));
        $this->assertSame(PaymentStatusEnum::CANCELLED->value, $payment->fresh()->status);
        $this->assertCount(1, $this->calls['void']);
        $this->assertArrayNotHasKey('capture', $this->calls);
    }

    public function testCancellingBeforeEmissionReleasesTheHold(): void
    {
        $this->fakeUniversal();
        $this->fakeProcessor();
        $order = $this->createOrder();
        $this->runActivity(AttachInsuranceQuoteActivity::class, $order);
        $payment = $this->holdPayment($order);

        $result = $this->runActivity(CancelInsuranceOrderActivity::class, $order);

        $this->assertSame('success', $result['status']);
        $this->assertSame(InsuranceStatusEnum::CANCELLED->value, $this->insuranceStatus($order));
        $this->assertSame(PaymentStatusEnum::CANCELLED->value, $payment->fresh()->status);
    }

    public function testAPolicyThatAppearsLaterIsNotCapturedBySync(): void
    {
        $this->fakeUniversal(policyReady: false);
        $this->fakeProcessor();
        $order = $this->createOrder();
        $this->runActivity(AttachInsuranceQuoteActivity::class, $order);
        $payment = $this->holdPayment($order);
        $this->runActivity(ReportInsurancePaymentActivity::class, $order);
        $this->attachDocument($order, 'registration');
        $this->runActivity(UploadInsuranceDocumentsActivity::class, $order);

        $emit = $this->runActivity(EmitInsurancePolicyActivity::class, $order);
        $this->assertSame('pending', $emit['status']);
        $this->assertFalse($emit['paymentCaptured']);

        $this->fakeUniversal(policyReady: true);
        $sync = $this->runActivity(SyncInsurancePolicyActivity::class, $order);

        $this->assertSame('success', $sync['status']);
        $this->assertSame(InsuranceStatusEnum::POLICY_ACTIVE->value, $this->insuranceStatus($order));
        $this->assertSame(
            PaymentStatusEnum::AUTHORIZED->value,
            $payment->fresh()->status,
            'known gap: sync stamps the policy but nothing captures the hold'
        );
    }

    private function contractedOrder(): Order
    {
        $company = $this->kanvasUser->getCurrentCompany();
        $this->pinPlatformCompany($company->getId());

        new SyncInsuranceProductsAction(
            new UniversalSegurosProvider($this->kanvasApp, $company, Mockery::mock(UniversalSegurosService::class)),
            $this->kanvasApp,
            $company
        )->execute();
        $company->set(InsuranceCustomFieldEnum::INSURER_COMPANY_ID->value, $company->getId());

        $plan = $this->seedPlan($this->seedInsurer($company, UniversalSegurosProvider::NAME), 'A-PA');
        InsuranceQuoteBinding::bind($this->kanvasApp->getId(), $this->kanvasUser->getId(), self::QUOTE, $plan->getId());

        $leadId = $this->graphQL(
            'mutation($input: SelectInsurancePlanInput!) { selectInsurancePlan(input: $input) { id } }',
            ['input' => ['plan_id' => $plan->getId(), 'quote_number' => self::QUOTE]]
        )->json('data.selectInsurancePlan.id');
        $this->assertNotNull($leadId);

        $response = $this->graphQL(
            'mutation($input: ContractInsurancePlanInput!) { contractInsurancePlan(input: $input) { id } }',
            ['input' => ['lead_id' => $leadId, 'customer' => self::CUSTOMER]]
        );
        $orderId = $response->json('data.contractInsurancePlan.id');
        $this->assertNotNull($orderId, json_encode($response->json()));

        $lead = Lead::findOrFail($leadId);
        $this->assertFalse($lead->hasOpenLeadStatus());
        $this->assertSame((int) $orderId, (int) $lead->get(InsuranceCustomFieldEnum::ORDER_ID->value));

        return Order::findOrFail($orderId);
    }

    public function testTheWholeChainFromSelectionToInvoicedPolicy(): void
    {
        $this->fakeUniversal();
        $this->fakeProcessor();
        $order = $this->contractedOrder();

        $this->assertEquals(6078.0, (float) $order->total_gross_amount);
        $this->assertSame(InsuranceStatusEnum::QUOTED->value, $this->insuranceStatus($order));

        $this->assertCount(1, $this->calls['updateQuote']);
        $attach = $this->runActivity(AttachInsuranceQuoteActivity::class, $order);
        $this->assertSame('skipped', $attach['status'], json_encode($attach));
        $this->assertCount(1, $this->calls['updateQuote'], 'the activity must not PUT the customer a second time');
        $this->assertMatchesRegularExpression('/^kanvas-[0-9a-f]{64}$/', $this->calls['updateQuote'][0]['data']['requestId']);
        $this->assertSame('Ana', $this->calls['updateQuote'][0]['data']['cliente']['nombre']);
        $this->assertSame('F', $this->calls['updateQuote'][0]['data']['cliente']['genero']);
        $this->assertSame(77, $this->calls['updateQuote'][0]['data']['vehiculo']['idModelo']);

        $payment = $this->holdPayment($order);

        $report = $this->runActivity(ReportInsurancePaymentActivity::class, $order);
        $this->assertSame('success', $report['status'], json_encode($report));
        $this->assertSame(InsuranceStatusEnum::PAID->value, $this->insuranceStatus($order));

        $this->attachDocument($order, 'registration');
        $this->runActivity(UploadInsuranceDocumentsActivity::class, $order);

        $emit = $this->runActivity(EmitInsurancePolicyActivity::class, $order);
        $this->assertSame('success', $emit['status'], json_encode($emit));
        $this->assertTrue($emit['paymentCaptured']);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->fresh()->status);
        $this->assertSame(InsuranceStatusEnum::POLICY_ACTIVE->value, $this->insuranceStatus($order));
        $this->assertCount(1, $this->calls['capture']);
        $this->assertArrayNotHasKey('void', $this->calls);

        $invoice = $this->runActivity(InvoiceInsurancePolicyActivity::class, $order);
        $this->assertSame('success', $invoice['status'], json_encode($invoice));
        $this->assertSame(self::POLICY, $this->calls['invoicePolicy'][0]['noPoliza']);
    }

    public function testTheWholeChainFromSelectionVoidsTheHoldWhenTheInsurerRefusesToEmit(): void
    {
        $this->fakeUniversal(emitRefused: true);
        $this->fakeProcessor();
        $order = $this->contractedOrder();
        $this->runActivity(AttachInsuranceQuoteActivity::class, $order);
        $payment = $this->holdPayment($order);
        $this->runActivity(ReportInsurancePaymentActivity::class, $order);
        $this->attachDocument($order, 'registration');
        $this->runActivity(UploadInsuranceDocumentsActivity::class, $order);

        $emit = $this->runActivity(EmitInsurancePolicyActivity::class, $order);

        $this->assertStringContainsString('Chasis no autorizado', $emit['message']);
        $this->assertSame(InsuranceStatusEnum::FAILED->value, $this->insuranceStatus($order));
        $this->assertSame(PaymentStatusEnum::CANCELLED->value, $payment->fresh()->status);
        $this->assertCount(1, $this->calls['void']);
        $this->assertArrayNotHasKey('capture', $this->calls);
        $this->assertArrayNotHasKey('invoicePolicy', $this->calls);
    }

    private function heldContractedOrder(): array
    {
        $order = $this->contractedOrder();
        $payment = $this->holdPayment($order);

        return [$order, $payment];
    }

    public function testAnInsurerRefusalOfThePaymentReportReleasesTheHold(): void
    {
        $this->fakeUniversal();
        $this->fakeProcessor();
        $this->failing['assignPaymentInformation'] = new ValidationException('Pago rechazado');
        [$order, $payment] = $this->heldContractedOrder();

        $report = $this->runActivity(ReportInsurancePaymentActivity::class, $order);

        $this->assertStringContainsString('Pago rechazado', $report['message']);
        $this->assertSame(InsuranceStatusEnum::FAILED->value, $this->insuranceStatus($order));
        $this->assertSame(PaymentStatusEnum::CANCELLED->value, $payment->fresh()->status);
        $this->assertCount(1, $this->calls['void']);
    }

    public function testAPendingPaymentReportReleasesTheHold(): void
    {
        $this->fakeUniversal();
        $this->fakeProcessor();
        [$order, $payment] = $this->heldContractedOrder();
        $provider = Mockery::mock(
            UniversalSegurosProvider::class,
            [$this->kanvasApp, $this->kanvasUser->getCurrentCompany(), $this->service]
        )->makePartial();
        $provider->shouldReceive('reportPayment')->andReturn(new PaymentReportResult(success: false, message: 'Queued'));
        app()->bind('insurance_provider.universal_seguros', fn () => $provider);

        $report = $this->runActivity(ReportInsurancePaymentActivity::class, $order);

        $this->assertSame('Queued', $report['message']);
        $this->assertSame(PaymentStatusEnum::CANCELLED->value, $payment->fresh()->status);
    }

    public function testATransientErrorWhileReportingLeavesTheHoldUntouched(): void
    {
        $this->fakeUniversal();
        $this->fakeProcessor();
        $this->failing['assignPaymentInformation'] = new RuntimeException('cURL error 28');
        [$order, $payment] = $this->heldContractedOrder();

        $report = $this->runActivity(ReportInsurancePaymentActivity::class, $order);

        $this->assertArrayHasKey('error', $report);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $payment->fresh()->status);
        $this->assertArrayNotHasKey('void', $this->calls);
    }

    public function testATransientErrorWhileEmittingLeavesTheHoldUntouched(): void
    {
        $this->fakeUniversal();
        $this->fakeProcessor();
        $this->failing['emit'] = new RuntimeException('cURL error 28');
        [$order, $payment] = $this->heldContractedOrder();
        $this->runActivity(ReportInsurancePaymentActivity::class, $order);
        $this->attachDocument($order, 'registration');
        $this->runActivity(UploadInsuranceDocumentsActivity::class, $order);

        $emit = $this->runActivity(EmitInsurancePolicyActivity::class, $order);

        $this->assertArrayHasKey('error', $emit);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $payment->fresh()->status);
        $this->assertArrayNotHasKey('void', $this->calls);
        $this->assertArrayNotHasKey('capture', $this->calls);
    }

    public function testAnOrderWithoutAQuoteNumberFailsEmissionAndReleasesTheHold(): void
    {
        $this->fakeUniversal();
        $this->fakeProcessor();
        [$order, $payment] = $this->heldContractedOrder();
        $order->del(InsuranceCustomFieldEnum::QUOTE_NUMBER->value);

        $emit = $this->runActivity(EmitInsurancePolicyActivity::class, $order);

        $this->assertTrue($emit['paymentReleased']);
        $this->assertSame(PaymentStatusEnum::CANCELLED->value, $payment->fresh()->status);
    }

    private function attachDocument(Order $order, string $fieldName): void
    {
        SafeUrlFetcher::fake(fn (string $url): string => 'fake-bytes');

        $file = new Filesystem([
            'users_id' => $this->kanvasUser->getId(),
            'apps_id' => $this->kanvasApp->getId(),
            'name' => 'matricula.pdf',
            'path' => 'insurance/matricula.pdf',
            'url' => 'https://files.example.com/matricula.pdf',
            'size' => '10',
            'file_type' => 'pdf',
        ]);
        $file->companies_id = $order->companies_id;
        $file->save();

        $order->addFile($file, $fieldName);
    }
}
