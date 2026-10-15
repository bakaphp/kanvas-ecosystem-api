<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\UniversalSeguros\Handlers\UniversalSegurosHandler;
use Kanvas\Connectors\UniversalSeguros\Providers\UniversalSegurosProvider;
use Kanvas\Connectors\UniversalSeguros\Services\UniversalSegurosService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Insurance\Actions\ReadInsuranceOrderAction;
use Kanvas\Insurance\Actions\SyncInsuranceProductsAction;
use Kanvas\Insurance\Activities\AttachInsuranceQuoteActivity;
use Kanvas\Insurance\Activities\ReportInsurancePaymentActivity;
use Kanvas\Insurance\Enums\InsuranceCatalogStatusEnum;
use Kanvas\Insurance\Enums\InsuranceCustomerFieldEnum;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Enums\InsuranceOrderStatusEnum;
use Kanvas\Insurance\Enums\InsuranceOrderTypeEnum;
use Kanvas\Insurance\Enums\InsuranceStatusEnum;
use Kanvas\Insurance\Models\InsurancePlan;
use Kanvas\Insurance\Services\InsuranceQuoteBinding;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Contracts\PaymentProcessorInterface;
use Kanvas\Souk\Payments\DataTransferObject\CaptureResult;
use Kanvas\Souk\Payments\DataTransferObject\VoidResult;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleCondition;
use Kanvas\Workflow\Rules\Models\RuleType;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\GraphQL\Souk\Traits\PaymentCases;
use Tests\Insurance\Concerns\BuildsSearchCatalog;
use Tests\Insurance\Concerns\CreatesVehicleProducts;
use Tests\Insurance\Concerns\SeedsInsuranceOrderPipeline;
use Tests\Insurance\Fakes\FakeCustomerProvider;
use Tests\TestCase;

class InsuranceCartOrderTest extends TestCase
{
    use BuildsSearchCatalog;
    use CreatesVehicleProducts;
    use DatabaseTransactions;
    use HasIntegrationCompany;
    use InventoryCases;
    use PaymentCases;
    use SeedsInsuranceOrderPipeline;

    protected $connectionsToTransact = [null, 'commerce', 'crm', 'inventory', 'ecosystem', 'social', 'workflow'];

    private const PROVIDER = UniversalSegurosProvider::NAME;

    private const QUOTE = '355898';

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

    private const CREATE_ORDER = '
        mutation($input: OrderCartInput!) {
            createOrderFromCart(input: $input) { order { id } }
        }
    ';

    private string $quote;

    private Apps $kanvasApp;

    private Users $kanvasUser;

    private FakeCustomerProvider $insurer;

    private InsurancePlan $plan;

    private Products $vehicle;

    private array $payments = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->quote = (string) random_int(100000, 999999999);

        $this->kanvasApp = app(Apps::class);
        $this->kanvasUser = auth()->user();
        $company = $this->kanvasUser->getCurrentCompany();
        $this->seedInsuranceOrderPipeline();
        $this->pinPlatformCompany($company->getId());
        $this->setAllowNoPaymentStatus(true, $this->kanvasApp);

        $this->setIntegration(
            $this->kanvasApp,
            IntegrationsEnum::UNIVERSAL_SEGUROS,
            UniversalSegurosHandler::class,
            $company,
            $this->kanvasUser,
        );

        new SyncInsuranceProductsAction(
            new UniversalSegurosProvider($this->kanvasApp, $company, Mockery::mock(UniversalSegurosService::class)),
            $this->kanvasApp,
            $company
        )->execute();
        $company->set(InsuranceCustomFieldEnum::INSURER_COMPANY_ID->value, $company->getId());

        $insurer = $this->insurer = new FakeCustomerProvider(self::PROVIDER, fn (): never => throw new LogicException('unused'));
        $this->app->bind('insurance_provider.' . self::PROVIDER, fn (): FakeCustomerProvider => $insurer);

        $this->plan = $this->seedPlan(
            $this->seedInsurer($company, self::PROVIDER),
            'A-PA',
            ['commission_rate' => 10]
        );
        $this->vehicle = $this->createVehicleProduct($this->kanvasUser);

        $region = Regions::getDefault($company, $this->kanvasApp);
        $this->createWarehouses((string) $region->getId());
    }

    private function variantIdOf(Products $product): int
    {
        return (int) $product->variants()->firstOrFail()->getId();
    }

    private function planVariantId(string $code = 'A-PA'): int
    {
        $product = Products::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->kanvasUser->getCurrentCompany()->getId())
            ->where('slug', UniversalSegurosProvider::NAME . '-' . strtolower($code))
            ->firstOrFail();

        return $this->variantIdOf($product);
    }

    private function bindQuote(?InsurancePlan $plan = null, ?string $quote = null): void
    {
        InsuranceQuoteBinding::bind(
            $this->kanvasApp->getId(),
            $this->kanvasUser->getId(),
            $quote ?? $this->quote,
            ($plan ?? $this->plan)->getId()
        );
    }

    private function payload(array $overrides = []): array
    {
        $input = [
            'cartId' => 0,
            'customer' => ['email' => 'ana@example.com', 'phone' => '8095550100'],
            'order_type' => InsuranceOrderTypeEnum::INSURANCE->value,
            'items' => [
                ['variant_id' => (string) $this->variantIdOf($this->vehicle), 'quantity' => 1, 'price' => 0],
                ['variant_id' => (string) $this->planVariantId(), 'quantity' => 1, 'price' => 116],
            ],
            'metadata' => ['data' => ['quote_number' => $this->quote, 'customer' => self::CUSTOMER]],
        ];

        return array_replace($input, $overrides);
    }

    private function createFromCart(array $overrides = []): Order
    {
        $company = $this->kanvasUser->getCurrentCompany();

        $response = $this->graphQL(
            self::CREATE_ORDER,
            ['input' => $this->payload($overrides)],
            [],
            ['X-Kanvas-Location' => $company->branch->uuid, 'X-Kanvas-App' => $this->kanvasApp->key]
        );

        $id = $response->json('data.createOrderFromCart.order.id');
        $this->assertNotNull($id, json_encode($response->json()));

        return Order::findOrFail($id);
    }

    private function runAttach(Order $order): array
    {
        $activity = new AttachInsuranceQuoteActivity(0, now()->toDateTimeString(), StoredWorkflow::make(), []);

        return $activity->execute($order->fresh(), $this->kanvasApp, []);
    }

    private function assertFailedWith(Order $order, array $result, string $reason): void
    {
        $order = $order->fresh();

        $this->assertArrayNotHasKey('status', $result, json_encode($result));
        $this->assertSame($reason, $order->get(InsuranceCustomFieldEnum::FAILURE_REASON->value));
        $this->assertSame(InsuranceStatusEnum::FAILED->value, $order->get(InsuranceCustomFieldEnum::STATUS->value));
        $this->assertSame(InsuranceOrderStatusEnum::FAILED->value, $order->orderStatus?->slug);
        $this->assertEmpty($order->get(InsuranceCustomFieldEnum::QUOTE_NUMBER->value));
    }

    public function testTheFrontendPayloadCreatesTwoItemsAndNoLegacyMetadata(): void
    {
        $this->bindQuote();

        $order = $this->createFromCart();

        $this->assertSame(2, $order->items()->count());
        $this->assertArrayNotHasKey('insurance', $order->metadata);
        $this->assertSame($this->quote, $order->metadata['data']['quote_number']);
        $this->assertSame('Ana', $order->metadata['data']['customer']['first_name']);
        $this->assertEquals(116.0, (float) $order->total_gross_amount);
        $this->assertSame(InsuranceOrderTypeEnum::INSURANCE->value, $order->orderType->name);
    }

    public function testAValidOrderIsQuotedAndStamped(): void
    {
        $this->bindQuote();
        $order = $this->createFromCart();

        $result = $this->runAttach($order);

        $order = $order->fresh();
        $this->assertSame('success', $result['status'], json_encode($result));
        $this->assertSame(InsuranceStatusEnum::QUOTED->value, $order->get(InsuranceCustomFieldEnum::STATUS->value));
        $this->assertSame(InsuranceOrderStatusEnum::QUOTED->value, $order->orderStatus?->slug);
        $this->assertSame(self::PROVIDER, $order->get(InsuranceCustomFieldEnum::PROVIDER->value));
        $this->assertSame('A-PA', $order->get(InsuranceCustomFieldEnum::PRODUCT_CODE->value));
        $this->assertEquals($this->quote, $order->get(InsuranceCustomFieldEnum::QUOTE_NUMBER->value));
        $this->assertEquals(116.0, (float) $order->get(InsuranceCustomFieldEnum::TOTAL->value));
        $this->assertSame((string) $this->plan->getId(), (string) $order->get(InsuranceCustomFieldEnum::PLAN_ID->value));
        $this->assertArrayNotHasKey('insurance', $order->metadata);
        $this->assertCount(1, $this->insurer->updates);
        $this->assertSame('Ana', $this->insurer->updates[0][1]['cliente']['nombre']);
        $this->assertNull($order->get(InsuranceCustomFieldEnum::FAILURE_REASON->value));
    }

    public function testRunningTheActivityAgainDoesNotPushTheCustomerTwice(): void
    {
        $this->bindQuote();
        $order = $this->createFromCart();
        $this->runAttach($order);

        $again = $this->runAttach($order);

        $this->assertSame('skipped', $again['status']);
        $this->assertCount(1, $this->insurer->updates);
    }

    public function testTheReaderDerivesEverythingFromTheItemsAndTheDataBlock(): void
    {
        $this->bindQuote();
        $order = $this->createFromCart();

        $data = new ReadInsuranceOrderAction($order->fresh())->execute();

        $this->assertFalse($data->legacy);
        $this->assertSame(self::PROVIDER, $data->provider);
        $this->assertSame('A-PA', $data->productCode);
        $this->assertSame($this->quote, $data->quoteNumber);
        $this->assertSame($this->variantIdOf($this->vehicle), $data->vehicle?->getId());
        $this->assertSame($this->planVariantId(), $data->plan?->getId());
        $this->assertSame('Ana', $data->customer?->toArray()['first_name']);
    }

    public function testTheReaderStillUnderstandsTheLegacyShape(): void
    {
        $order = $this->legacyOrder(null);

        $data = new ReadInsuranceOrderAction($order)->execute();

        $this->assertTrue($data->legacy);
        $this->assertSame(self::PROVIDER, $data->provider);
        $this->assertSame('A-PA', $data->productCode);
        $this->assertSame('LEG-1', $data->quoteNumber);
        $this->assertSame(['tipo' => 'APA'], $data->legacyPayload);
        $this->assertNull($data->customer);
    }

    public function testNoVehicleItemFailsTheOrder(): void
    {
        $this->bindQuote();
        $order = $this->createFromCart(['items' => [
            ['variant_id' => (string) $this->planVariantId(), 'quantity' => 1, 'price' => 116],
        ]]);

        $result = $this->runAttach($order);

        $this->assertFailedWith($order, $result, 'Choose exactly one vehicle to insure');
        $this->assertCount(0, $this->insurer->updates);
    }

    public function testAnItemThatIsNotAVehicleProductIsNotAVehicle(): void
    {
        $this->bindQuote();
        $order = $this->createFromCart(['items' => [
            ['variant_id' => (string) $this->planVariantId('A-KM'), 'quantity' => 1, 'price' => 0],
            ['variant_id' => (string) $this->planVariantId(), 'quantity' => 1, 'price' => 116],
        ]]);

        $result = $this->runAttach($order);

        $this->assertFailedWith($order, $result, 'Choose exactly one vehicle to insure');
    }

    public function testAVehicleOfAnotherUserIsRejected(): void
    {
        $this->bindQuote();
        $other = Users::query()->where('id', '>', 0)->where('id', '!=', $this->kanvasUser->getId())->firstOrFail();
        $foreign = $this->createVehicleProduct($other);
        $order = $this->createFromCart(['items' => [
            ['variant_id' => (string) $this->variantIdOf($foreign), 'quantity' => 1, 'price' => 0],
            ['variant_id' => (string) $this->planVariantId(), 'quantity' => 1, 'price' => 116],
        ]]);

        $result = $this->runAttach($order);

        $this->assertFailedWith($order, $result, 'The vehicle does not belong to this account');
        $this->assertCount(0, $this->insurer->updates);
    }

    public function testNoPlanItemFailsTheOrder(): void
    {
        $this->bindQuote();
        $order = $this->createFromCart(['items' => [
            ['variant_id' => (string) $this->variantIdOf($this->vehicle), 'quantity' => 1, 'price' => 0],
        ]]);

        $result = $this->runAttach($order);

        $this->assertFailedWith($order, $result, 'Choose exactly one insurance plan');
    }

    public function testAnInactivePlanIsNotSellable(): void
    {
        $this->bindQuote();
        $order = $this->createFromCart();
        $this->plan->update(['status' => InsuranceCatalogStatusEnum::INACTIVE->value]);

        $result = $this->runAttach($order);

        $this->assertFailedWith($order, $result, 'This plan is no longer available');
    }

    public function testAMissingQuoteNumberFailsTheOrder(): void
    {
        $order = $this->createFromCart(['metadata' => ['data' => ['customer' => self::CUSTOMER]]]);

        $result = $this->runAttach($order);

        $this->assertFailedWith($order, $result, 'Select a plan quote before contracting');
    }

    public function testAQuoteTheUserNeverSearchedIsRejected(): void
    {
        $order = $this->createFromCart();

        $result = $this->runAttach($order);

        $this->assertFailedWith($order, $result, 'This quote was not issued for this account, please search again');
        $this->assertCount(0, $this->insurer->updates);
    }

    public function testTheContractPathBuildsTheSameOrderAsTheCartPath(): void
    {
        $this->bindQuote();
        $cartOrder = $this->createFromCart();
        $this->runAttach($cartOrder);

        $contractQuote = $this->quote . '1';
        InsuranceQuoteBinding::bind(
            $this->kanvasApp->getId(),
            $this->kanvasUser->getId(),
            $contractQuote,
            $this->plan->getId(),
            $this->vehicle->getId()
        );
        $leadId = $this->graphQL(
            'mutation($input: SelectInsurancePlanInput!) { selectInsurancePlan(input: $input) { id } }',
            ['input' => ['plan_id' => $this->plan->getId(), 'quote_number' => $contractQuote]]
        )->json('data.selectInsurancePlan.id');
        $response = $this->graphQL(
            'mutation($input: ContractInsurancePlanInput!) { contractInsurancePlan(input: $input) { id } }',
            ['input' => ['lead_id' => $leadId, 'customer' => self::CUSTOMER]]
        );
        $contractId = $response->json('data.contractInsurancePlan.id');
        $this->assertNotNull($contractId, json_encode($response->json()));

        $cartOrder = $cartOrder->fresh();
        $contractOrder = Order::findOrFail($contractId);
        $cart = new ReadInsuranceOrderAction($cartOrder)->execute();
        $contract = new ReadInsuranceOrderAction($contractOrder)->execute();

        $this->assertFalse($contract->legacy);
        $this->assertSame($cart->provider, $contract->provider);
        $this->assertSame($cart->productCode, $contract->productCode);
        $this->assertSame($this->quote, $cart->quoteNumber);
        $this->assertSame($contractQuote, $contract->quoteNumber);
        $this->assertSame($cart->vehicle?->getId(), $contract->vehicle?->getId());
        $this->assertSame($cart->plan?->getId(), $contract->plan?->getId());
        $this->assertSame($cart->customer?->toArray(), $contract->customer?->toArray());
        $this->assertSame($this->itemShape($cartOrder), $this->itemShape($contractOrder));
        $this->assertSame(array_keys($cartOrder->metadata), array_keys($contractOrder->metadata));
        $this->assertSame(array_keys($cartOrder->metadata['data']), array_keys($contractOrder->metadata['data']));
        $this->assertSame($cartOrder->orderType->name, $contractOrder->orderType->name);
        $this->assertSame($cartOrder->orderStatus->slug, $contractOrder->orderStatus->slug);

        foreach (['total_gross_amount', 'tax_amount', 'discount_amount', 'shipping_price_gross_amount'] as $column) {
            $this->assertEquals((float) $cartOrder->{$column}, (float) $contractOrder->{$column}, $column);
        }

        foreach ([
            InsuranceCustomFieldEnum::PROVIDER,
            InsuranceCustomFieldEnum::PRODUCT_CODE,
            InsuranceCustomFieldEnum::STATUS,
            InsuranceCustomFieldEnum::TOTAL,
            InsuranceCustomFieldEnum::PLAN_ID,
        ] as $field) {
            $this->assertEquals($cartOrder->get($field->value), $contractOrder->get($field->value), $field->value);
        }
    }

    private function itemShape(Order $order): array
    {
        return $order->items()->orderBy('id')->get()
            ->map(fn ($item): array => [
                (int) $item->variant_id,
                (float) $item->unit_price_gross_amount,
                (float) $item->quantity,
            ])
            ->all();
    }

    public function testAQuoteBoundToAnotherPlanIsRejected(): void
    {
        $other = $this->seedPlan($this->plan->insurer, 'A-KM');
        $this->bindQuote($other);
        $order = $this->createFromCart();

        $result = $this->runAttach($order);

        $this->assertFailedWith($order, $result, 'This quote belongs to a different plan');
    }

    public function testAnIncompleteCustomerIsRejectedWithCanonicalNames(): void
    {
        $this->bindQuote();
        $customer = array_diff_key(self::CUSTOMER, ['birth_date' => 1, 'sex' => 1]);
        $order = $this->createFromCart(['metadata' => ['data' => ['quote_number' => $this->quote, 'customer' => $customer]]]);

        $result = $this->runAttach($order);

        $reason = (string) $order->fresh()->get(InsuranceCustomFieldEnum::FAILURE_REASON->value);
        $this->assertStringContainsString('birth date', $reason);
        $this->assertStringContainsString('sex', $reason);
        $this->assertStringNotContainsString('fechaNacimiento', $reason);
        $this->assertSame(InsuranceStatusEnum::FAILED->value, $order->fresh()->get(InsuranceCustomFieldEnum::STATUS->value));
        $this->assertCount(0, $this->insurer->updates);
        $this->assertArrayNotHasKey('status', $result);
    }

    public function testAPlanPriceThatDiffersFromTheInsurerTotalIsRejected(): void
    {
        $this->bindQuote();
        $order = $this->createFromCart(['items' => [
            ['variant_id' => (string) $this->variantIdOf($this->vehicle), 'quantity' => 1, 'price' => 0],
            ['variant_id' => (string) $this->planVariantId(), 'quantity' => 1, 'price' => 100],
        ]]);

        $result = $this->runAttach($order);

        $this->assertFailedWith($order, $result, 'The price of the plan changed, please search again');
    }

    public function testAnInsurerRejectionFailsTheOrderWithoutLeakingTheirText(): void
    {
        $this->bindQuote();
        $this->insurer->rejectCustomerWith = 'Cedula invalida segun JCE';
        $order = $this->createFromCart();

        $result = $this->runAttach($order);

        $reason = (string) $order->fresh()->get(InsuranceCustomFieldEnum::FAILURE_REASON->value);
        $this->assertStringNotContainsString('JCE', $reason);
        $this->assertStringContainsString('JCE', $result['message']);
        $this->assertSame(InsuranceStatusEnum::FAILED->value, $order->fresh()->get(InsuranceCustomFieldEnum::STATUS->value));
    }

    public function testTheSameQuoteCannotBeContractedTwice(): void
    {
        $this->bindQuote();
        $first = $this->createFromCart();
        $this->runAttach($first);
        $second = $this->createFromCart();

        $result = $this->runAttach($second);

        $this->assertFailedWith($second, $result, 'This quote has already been contracted');
        $this->assertSame(
            InsuranceStatusEnum::QUOTED->value,
            $first->fresh()->get(InsuranceCustomFieldEnum::STATUS->value)
        );
    }

    public function testAFailedOrderThatStillGetsAHeldPaymentHasItReleasedAndNothingCaptured(): void
    {
        $processor = Mockery::mock(PaymentProcessorInterface::class);
        $processor->shouldReceive('capture')->andReturnUsing(function (Payments $payment) {
            $this->payments['capture'][] = $payment->getId();

            return new CaptureResult(true, 'captured', 'tx');
        });
        $processor->shouldReceive('void')->andReturnUsing(function (Payments $payment) {
            $this->payments['void'][] = $payment->getId();
            $payment->update(['status' => PaymentStatusEnum::CANCELLED->value]);

            return new VoidResult(true, 'voided', 'tx');
        });
        app()->bind('payment_processor.azul', fn () => $processor);

        $order = $this->createFromCart();
        $this->runAttach($order);
        $payment = $order->payments()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $order->companies_id,
            'users_id' => $this->kanvasUser->getId(),
            'amount' => 116,
            'payment_date' => date('Y-m-d'),
            'concept' => 'insurance',
            'currency' => 'DOP',
            'status' => PaymentStatusEnum::AUTHORIZED->value,
            'payment_method' => 'card',
            'processor' => 'azul',
            'payment_intent_id' => '44859568',
            'authorization_code' => 'OK7396',
            'metadata' => ['data' => ['iso_code' => '00']],
        ]);

        $report = new ReportInsurancePaymentActivity(0, now()->toDateTimeString(), StoredWorkflow::make(), [])
            ->execute($order->fresh(), $this->kanvasApp, []);

        $this->assertTrue($report['paymentReleased']);
        $this->assertSame(PaymentStatusEnum::CANCELLED->value, $payment->fresh()->status);
        $this->assertArrayNotHasKey('capture', $this->payments);
    }

    public function testAnOrderOfAnotherTypeIsUntouched(): void
    {
        $order = $this->createFromCart(['order_type' => 'pasorapido-like']);

        $result = $this->runAttach($order);

        $this->assertSame('skipped', $result['status']);
        $this->assertEmpty($order->fresh()->get(InsuranceCustomFieldEnum::STATUS->value));
        $this->assertEmpty($order->fresh()->get(InsuranceCustomFieldEnum::FAILURE_REASON->value));
    }

    public function testATypelessOrderWithTheOldMetadataIsProcessedTheOldWay(): void
    {
        $order = $this->legacyOrder(null);
        $this->insurer->total = 6078.0;

        $result = $this->runAttach($order);

        $order = $order->fresh();
        $this->assertSame('success', $result['status'], json_encode($result));
        $this->assertSame(InsuranceStatusEnum::QUOTED->value, $order->get(InsuranceCustomFieldEnum::STATUS->value));
        $this->assertSame('LEG-1', $order->get(InsuranceCustomFieldEnum::QUOTE_NUMBER->value));
        $this->assertEquals(6078.0, (float) $order->get(InsuranceCustomFieldEnum::TOTAL->value));
        $this->assertNull($order->orderStatus);
        $this->assertCount(1, $this->insurer->updates);
        $this->assertSame(['nombre' => 'Ana'], $this->insurer->updates[0][1]['cliente']);
        $this->assertSame('APA', $this->insurer->updates[0][1]['tipo']);
        $this->assertEmpty($order->get(InsuranceCustomFieldEnum::FAILURE_REASON->value));
    }

    public function testATypelessOrderWithoutInsuranceMetadataIsSkipped(): void
    {
        $order = $this->legacyOrder(null, []);

        $result = $this->runAttach($order);

        $this->assertSame('skipped', $result['status']);
        $this->assertEmpty($order->fresh()->get(InsuranceCustomFieldEnum::STATUS->value));
    }

    public function testAVehicleLineWithAPriceIsRejected(): void
    {
        $this->bindQuote();
        $order = $this->createFromCart(['items' => [
            ['variant_id' => (string) $this->variantIdOf($this->vehicle), 'quantity' => 1, 'price' => 50],
            ['variant_id' => (string) $this->planVariantId(), 'quantity' => 1, 'price' => 116],
        ]]);

        $result = $this->runAttach($order);

        $this->assertFailedWith($order, $result, 'The vehicle line cannot carry a price');
        $this->assertCount(0, $this->insurer->updates);
    }

    public function testAnOrderTotalDifferentFromThePlanPriceIsRejected(): void
    {
        $this->bindQuote();
        $order = $this->createFromCart();
        $order->update(['total_gross_amount' => 200]);

        $result = $this->runAttach($order);

        $this->assertFailedWith($order, $result, 'The order total does not match the plan price');
        $this->assertCount(0, $this->insurer->updates);
    }

    public function testTaxShippingOrDiscountOnTheOrderIsRejected(): void
    {
        foreach (['tax_amount', 'shipping_price_gross_amount', 'discount_amount'] as $column) {
            $quote = (string) random_int(100000, 999999999);
            $this->quote = $quote;
            $this->bindQuote();
            $order = $this->createFromCart();
            $order->update([$column => 5]);

            $result = $this->runAttach($order);

            $this->assertFailedWith($order, $result, 'An insurance order cannot carry tax, shipping or discounts');
        }

        $this->assertCount(0, $this->insurer->updates);
    }

    public function testAQuantityOfTwoOnALineIsRejected(): void
    {
        $this->bindQuote();
        $order = $this->createFromCart(['items' => [
            ['variant_id' => (string) $this->variantIdOf($this->vehicle), 'quantity' => 1, 'price' => 0],
            ['variant_id' => (string) $this->planVariantId(), 'quantity' => 2, 'price' => 116],
        ]]);

        $result = $this->runAttach($order);

        $this->assertFailedWith($order, $result, 'An insurance order takes one vehicle and one plan');
    }

    public function testAnExtraThirdItemIsRejected(): void
    {
        $this->bindQuote();
        $extra = $this->createVehicleProduct($this->kanvasUser, null, 'parking');
        $order = $this->createFromCart(['items' => [
            ['variant_id' => (string) $this->variantIdOf($this->vehicle), 'quantity' => 1, 'price' => 0],
            ['variant_id' => (string) $this->planVariantId(), 'quantity' => 1, 'price' => 116],
            ['variant_id' => (string) $this->variantIdOf($extra), 'quantity' => 1, 'price' => 0],
        ]]);

        $result = $this->runAttach($order);

        $this->assertFailedWith($order, $result, 'An insurance order takes one vehicle and one plan');
    }

    public function testAnExpiredQuoteBindingIsToldApartFromANeverSearchedQuote(): void
    {
        config(['insurance.quote_binding_ttl' => 1]);
        $this->bindQuote();
        $order = $this->createFromCart();
        sleep(2);

        $result = $this->runAttach($order);

        $this->assertFailedWith($order, $result, 'This quote has expired, please search again');
    }

    public function testAnAbandonedOrderWithoutStatusDoesNotBlockARetryOfTheSameQuote(): void
    {
        $this->bindQuote();
        $abandoned = $this->createFromCart();
        $retry = $this->createFromCart();

        $result = $this->runAttach($retry);

        $this->assertSame('success', $result['status'], json_encode($result));
        $this->assertEmpty($abandoned->fresh()->get(InsuranceCustomFieldEnum::STATUS->value));
    }

    public function testAnUntypedOrderOfAnotherVerticalWithArraysInItsDataIsSkippedWithoutError(): void
    {
        foreach ([
            ['data' => ['customer' => ['first_name' => 'X'], 'quote_number' => ['a']]],
            ['data' => ['customer' => ['first_name' => ['nested', 'array']]]],
            ['data' => ['customer' => 'plain-text']],
        ] as $metadata) {
            $order = $this->legacyOrder(null, $metadata);

            $result = $this->runAttach($order);

            $this->assertSame('skipped', $result['status']);
        }
    }

    public function testNestedArraysInTheCustomerSnapshotNeverRaiseAConversionError(): void
    {
        $this->bindQuote();
        $customer = self::CUSTOMER + ['job_title' => ['a', 'b']];
        $order = $this->createFromCart(['metadata' => ['data' => ['quote_number' => $this->quote, 'customer' => $customer]]]);

        $data = new ReadInsuranceOrderAction($order->fresh())->execute();

        $this->assertNull($data->customer?->get(InsuranceCustomerFieldEnum::JOB_TITLE));
        $this->assertSame('Ana', $data->customer?->get(InsuranceCustomerFieldEnum::FIRST_NAME));
    }

    public function testALegacyBlockMissingItsProviderIsSkippedAsBefore(): void
    {
        $order = $this->legacyOrder(null, ['insurance' => ['product' => 'A-PA', 'quote_number' => 'X-1']]);

        $result = $this->runAttach($order);

        $this->assertSame('skipped', $result['status']);
        $this->assertCount(0, $this->insurer->updates);
        $this->assertFalse(new ReadInsuranceOrderAction($order->fresh())->execute()->legacyComplete);
    }

    public function testALegacyBlockMissingItsQuoteNumberIsSkippedAsBefore(): void
    {
        $order = $this->legacyOrder(null, ['insurance' => ['provider' => self::PROVIDER, 'product' => 'A-PA']]);

        $this->assertSame('skipped', $this->runAttach($order)['status']);
    }

    public function testASynchronousRuleRunsTheActivityInsideTheRequest(): void
    {
        $this->bindQuote();
        $order = $this->createFromCart();
        $this->createSyncAttachRule($order);

        $order->fireWorkflow(WorkflowEnum::CREATED->value, true, ['app' => $this->kanvasApp]);

        $this->assertSame(
            InsuranceStatusEnum::QUOTED->value,
            $order->fresh()->get(InsuranceCustomFieldEnum::STATUS->value)
        );
    }

    public function testAnUnexpectedFaultFailsTheOrderWithAGenericReasonAndDoesNotThrow(): void
    {
        $this->bindQuote();
        $order = $this->createFromCart();
        $this->app->bind('insurance_provider.' . self::PROVIDER, fn () => throw new RuntimeException('cURL error 28'));

        $result = $this->runAttach($order);

        $order = $order->fresh();
        $this->assertArrayNotHasKey('status', $result);
        $this->assertSame('cURL error 28', $result['message']);
        $this->assertSame(InsuranceStatusEnum::FAILED->value, $order->get(InsuranceCustomFieldEnum::STATUS->value));
        $this->assertStringNotContainsString('cURL', (string) $order->get(InsuranceCustomFieldEnum::FAILURE_REASON->value));
    }

    private function createSyncAttachRule(Order $order): Rule
    {
        $ruleType = RuleType::firstOrCreate(['name' => WorkflowEnum::CREATED->value]);
        $systemModule = SystemModulesRepository::getByModelName(Order::class, $this->kanvasApp);

        $rule = Rule::factory()->create([
            'systems_modules_id' => $systemModule->getId(),
            'rules_types_id' => $ruleType->getId(),
            'companies_id' => $order->companies_id,
            'apps_id' => $order->apps_id,
            'pattern' => '1',
            'params' => [],
            'is_async' => false,
        ]);

        RuleCondition::factory()->create([
            'rules_id' => $rule->getId(),
            'attribute_name' => 'currentEventTypeName',
            'operator' => '==',
            'value' => WorkflowEnum::CREATED->value,
        ]);

        $action = Action::factory()->create([
            'model_name' => AttachInsuranceQuoteActivity::class,
            'name' => 'Attach insurance quote',
        ]);

        $ruleWorkflowAction = RuleWorkflowAction::factory()->create([
            'actions_id' => $action->getId(),
            'system_modules_id' => $systemModule->getId(),
        ]);

        RuleAction::factory()->create([
            'rules_id' => $rule->getId(),
            'rules_workflow_actions_id' => $ruleWorkflowAction->getId(),
            'weight' => 0,
        ]);

        return $rule;
    }

    private function legacyOrder(?string $type, ?array $metadata = null): Order
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
                'metadata' => $metadata ?? ['insurance' => [
                    'provider' => self::PROVIDER,
                    'product' => 'A-PA',
                    'quote_number' => 'LEG-1',
                    'payload' => ['tipo' => 'APA'],
                    'cliente' => ['nombre' => 'Ana'],
                ]],
            ]);

        if ($type !== null) {
            $order->setOrderType($type);
        }

        return $order;
    }
}
