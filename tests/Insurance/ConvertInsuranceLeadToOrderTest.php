<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\UniversalSeguros\Providers\UniversalSegurosProvider;
use Kanvas\Connectors\UniversalSeguros\Services\UniversalSegurosService;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Insurance\Actions\ConvertInsuranceLeadToOrderAction;
use Kanvas\Insurance\Actions\ReadInsuranceOrderAction;
use Kanvas\Insurance\Actions\SyncInsuranceProductsAction;
use Kanvas\Insurance\DataTransferObject\InsuranceLeadContract;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Enums\InsuranceOrderStatusEnum;
use Kanvas\Insurance\Enums\InsuranceOrderTypeEnum;
use Kanvas\Insurance\Enums\InsuranceProductTypeEnum;
use Kanvas\Insurance\Enums\InsuranceStatusEnum;
use Kanvas\Insurance\Models\InsurancePlan;
use Kanvas\Insurance\Services\InsuranceQuoteBinding;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderTypes;
use LogicException;
use Mockery;
use Tests\Insurance\Concerns\BuildsSearchCatalog;
use Tests\Insurance\Concerns\CreatesVehicleProducts;
use Tests\Insurance\Concerns\SeedsInsuranceOrderPipeline;
use Tests\Insurance\Fakes\FakeCustomerProvider;
use Tests\TestCase;

class ConvertInsuranceLeadToOrderTest extends TestCase
{
    use DatabaseTransactions;
    use BuildsSearchCatalog;
    use CreatesVehicleProducts;
    use SeedsInsuranceOrderPipeline;

    protected $connectionsToTransact = [null, 'commerce', 'crm', 'inventory', 'ecosystem', 'social'];

    private const PROVIDER = UniversalSegurosProvider::NAME;

    private const CONTRACT = '
        mutation($input: ContractInsurancePlanInput!) {
            contractInsurancePlan(input: $input) { id }
        }
    ';

    private const SELECT = '
        mutation($input: SelectInsurancePlanInput!) {
            selectInsurancePlan(input: $input) { id }
        }
    ';

    private const COMPLETE_CUSTOMER = [
        'first_name' => 'Ana',
        'birth_date' => '1990-05-17',
        'document_number' => '00100000001',
        'sex' => 'female',
    ];

    private FakeCustomerProvider $customerProvider;

    private OrderTypes $insuranceOrderType;

    private ?Products $vehicle = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->insuranceOrderType = $this->seedInsuranceOrderPipeline();
    }

    private function bindCustomerProvider(): void
    {
        $provider = $this->customerProvider = new FakeCustomerProvider(
            self::PROVIDER,
            fn (): never => throw new LogicException('not used'),
        );

        $this->app->bind('insurance_provider.' . self::PROVIDER, fn (): FakeCustomerProvider => $provider);
    }

    private function sellablePlan(bool $withCustomerData = true): InsurancePlan
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $this->pinPlatformCompany($company->getId());

        new SyncInsuranceProductsAction(
            new UniversalSegurosProvider($app, $company, Mockery::mock(UniversalSegurosService::class)),
            $app,
            $company
        )->execute();
        $company->set(InsuranceCustomFieldEnum::INSURER_COMPANY_ID->value, $company->getId());

        if ($withCustomerData) {
            $this->bindCustomerProvider();
        } else {
            $this->bindFakeProvider(self::PROVIDER);
        }

        return $this->seedPlan(
            $this->seedInsurer($company, self::PROVIDER),
            'A-PA',
            ['commission_rate' => 10]
        );
    }

    private function vehicle(): Products
    {
        return $this->vehicle ??= $this->createVehicleProduct(auth()->user());
    }

    private function variantIdOf(Products $product): int
    {
        return (int) $product->variants()->orderBy('id')->firstOrFail()->getId();
    }

    private function selectedLead(InsurancePlan $plan, string $quoteNumber = 'QN-1'): Lead
    {
        return $this->selectedLeadWithVehicle($plan, $this->variantIdOf($this->vehicle()), $quoteNumber);
    }

    private function selectedLeadWithVehicle(InsurancePlan $plan, ?int $vehicleVariantId, string $quoteNumber = 'QN-1'): Lead
    {
        InsuranceQuoteBinding::bind(
            app(Apps::class)->getId(),
            auth()->user()->getId(),
            $quoteNumber,
            $plan->getId(),
            $vehicleVariantId
        );

        $id = $this->graphQL(self::SELECT, ['input' => ['plan_id' => $plan->getId(), 'quote_number' => $quoteNumber]])
            ->assertSuccessful()
            ->json('data.selectInsurancePlan.id');

        $this->assertNotNull($id);

        return Lead::findOrFail($id);
    }

    private function contract(Lead $lead, array $customer = []): array
    {
        return ['input' => ['lead_id' => $lead->getId(), 'customer' => $customer ?: null]];
    }

    private function contractAction(Lead $lead, array $data = []): ConvertInsuranceLeadToOrderAction
    {
        return new ConvertInsuranceLeadToOrderAction(
            InsuranceLeadContract::fromMultiple(
                app(Apps::class),
                auth()->user(),
                auth()->user()->getCurrentCompany(),
                ['lead_id' => $lead->getId()] + $data
            )
        );
    }

    public function testMissingCustomerDataIsRejectedWithCanonicalNamesAndNoOrderIsCreated(): void
    {
        $lead = $this->selectedLead($this->sellablePlan());

        $response = $this->graphQL(self::CONTRACT, $this->contract($lead, ['first_name' => 'Ana']));

        $message = (string) $response->json('errors.0.message');
        $this->assertStringContainsString('birth date', $message);
        $this->assertStringContainsString('document number', $message);
        $this->assertStringContainsString('sex', $message);
        $this->assertStringNotContainsString('fechaNacimiento', $message);
        $this->assertSame(0, Order::query()->where('reference', 'insurance-lead-' . $lead->getId())->count());
        $this->assertEmpty($lead->fresh()->get(InsuranceCustomFieldEnum::ORDER_ID->value));
        $this->assertTrue($lead->fresh()->hasOpenLeadStatus());
    }

    public function testWhatWasSuppliedIsRememberedSoTheNextAttemptOnlyNeedsTheRest(): void
    {
        $lead = $this->selectedLead($this->sellablePlan());

        $this->graphQL(self::CONTRACT, $this->contract($lead, ['birth_date' => '1990-05-17', 'sex' => 'female']));
        $response = $this->graphQL(self::CONTRACT, $this->contract($lead, ['document_number' => '00100000001']));

        $this->assertNotNull($response->json('data.contractInsurancePlan.id'), json_encode($response->json()));
    }

    public function testSuppliedCustomerDataConvertsTheLeadIntoAnOrderLinkedBothWays(): void
    {
        $plan = $this->sellablePlan();
        $lead = $this->selectedLead($plan);

        $response = $this->graphQL(self::CONTRACT, $this->contract($lead, self::COMPLETE_CUSTOMER))->assertSuccessful();
        $orderId = $response->json('data.contractInsurancePlan.id');
        $this->assertNotNull($orderId, json_encode($response->json()));

        $order = Order::findOrFail($orderId);
        $lead = $lead->fresh();

        $this->assertArrayNotHasKey('insurance', $order->metadata);
        $this->assertSame('QN-1', $order->metadata['data']['quote_number']);
        $this->assertSame('Ana', $order->metadata['data']['customer']['first_name']);
        $this->assertSame('00100000001', $order->metadata['data']['customer']['document_number']);
        $this->assertSame($lead->getId(), (int) $order->get(InsuranceCustomFieldEnum::LEAD_ID->value));
        $this->assertSame($order->getId(), (int) $lead->get(InsuranceCustomFieldEnum::ORDER_ID->value));
        $this->assertSame(InsuranceStatusEnum::QUOTED->value, $order->get(InsuranceCustomFieldEnum::STATUS->value));
        $this->assertSame($plan->insurer->provider, $order->get(InsuranceCustomFieldEnum::PROVIDER->value));
        $this->assertFalse($lead->hasOpenLeadStatus());
        $this->assertSame('Won', $lead->status()->firstOrFail()->name);
    }

    public function testThePriceComesFromTheLeadAndIgnoresWhatTheClientSends(): void
    {
        $lead = $this->selectedLead($this->sellablePlan());

        $order = $this->contractAction(
            $lead,
            [
                'customer' => self::COMPLETE_CUSTOMER,
                'total' => 1,
                'tax' => 0,
                'quote_number' => 'TAMPERED',
                'provider' => 'other',
                'product' => 'A-PL',
            ]
        )->execute();

        $this->assertEquals(116.0, (float) $order->total_gross_amount);
        $this->assertEquals(116.0, (float) $order->get(InsuranceCustomFieldEnum::TOTAL->value));
        $this->assertSame('QN-1', $order->metadata['data']['quote_number']);
        $this->assertSame(self::PROVIDER, $order->get(InsuranceCustomFieldEnum::PROVIDER->value));
        $this->assertSame('A-PA', $order->get(InsuranceCustomFieldEnum::PRODUCT_CODE->value));
    }

    public function testGraphQLRefusesAnyFieldThatCouldCarryAPrice(): void
    {
        $lead = $this->selectedLead($this->sellablePlan());

        $response = $this->graphQL(self::CONTRACT, ['input' => ['lead_id' => $lead->getId(), 'total' => 1]]);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertSame(0, Order::query()->where('reference', 'insurance-lead-' . $lead->getId())->count());
    }

    public function testConvertingTheSameLeadTwiceReturnsTheSameOrder(): void
    {
        $lead = $this->selectedLead($this->sellablePlan());

        $first = $this->graphQL(self::CONTRACT, $this->contract($lead, self::COMPLETE_CUSTOMER))->json('data.contractInsurancePlan.id');
        $second = $this->graphQL(self::CONTRACT, $this->contract($lead))->json('data.contractInsurancePlan.id');

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
        $this->assertSame(1, Order::query()->where('reference', 'insurance-lead-' . $lead->getId())->count());
    }

    public function testAnotherUsersLeadIsRejected(): void
    {
        $lead = $this->selectedLead($this->sellablePlan());
        $lead->users_id = $this->createUser()->getId();
        $lead->saveQuietly();

        $this->expectException(ValidationException::class);

        $this->contractAction($lead, ['customer' => self::COMPLETE_CUSTOMER]);
    }

    public function testALeadOfAnotherTenantIsRejected(): void
    {
        $lead = $this->selectedLead($this->sellablePlan());
        $lead->companies_id = $this->createUser()->getCurrentCompany()->getId();
        $lead->saveQuietly();

        $this->expectException(ModelNotFoundException::class);

        $this->contractAction($lead, ['customer' => self::COMPLETE_CUSTOMER]);
    }

    public function testAClosedLeadCannotBeContracted(): void
    {
        $lead = $this->selectedLead($this->sellablePlan());
        $lead->close();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('closed');

        $this->contractAction($lead, ['customer' => self::COMPLETE_CUSTOMER])->execute();
    }

    public function testSelectingTheSamePlanAfterConversionOpensANewLeadInsteadOfReusingTheWonOne(): void
    {
        $plan = $this->sellablePlan();
        $won = $this->selectedLead($plan, 'QN-1');
        $this->graphQL(self::CONTRACT, $this->contract($won, self::COMPLETE_CUSTOMER))->assertSuccessful();

        $next = $this->selectedLead($plan, 'QN-2');

        $this->assertNotSame($won->getId(), $next->getId());
        $this->assertSame('QN-1', (string) $won->fresh()->get(InsuranceCustomFieldEnum::QUOTE_NUMBER->value));
        $this->assertTrue($next->hasOpenLeadStatus());
    }

    public function testAProviderThatAsksNothingOfTheCustomerStillConvertsWithoutAnInsurerPut(): void
    {
        $lead = $this->selectedLead($this->sellablePlan(withCustomerData: false));

        $order = $this->contractAction($lead)->execute();

        $this->assertSame(2, $order->items()->count());
        $this->assertArrayNotHasKey('insurance', $order->metadata);
    }

    public function testAPlanWithoutASellableProductIsRefusedBeforeAnyOrderExists(): void
    {
        $plan = $this->sellablePlan();
        $lead = $this->selectedLead($plan);
        $plan->external_code = 'A-PL-MISSING';
        $plan->saveQuietly();

        $response = $this->graphQL(self::CONTRACT, $this->contract($lead, self::COMPLETE_CUSTOMER));

        $this->assertStringContainsString('not available', (string) $response->json('errors.0.message'));
        $this->assertSame(0, Order::query()->where('reference', 'insurance-lead-' . $lead->getId())->count());
    }

    public function testALeadWhosePriceWasEditedAfterSelectionIsRefused(): void
    {
        $lead = $this->selectedLead($this->sellablePlan());
        $lead->set(InsuranceCustomFieldEnum::TOTAL->value, 1);

        $response = $this->graphQL(self::CONTRACT, $this->contract($lead, self::COMPLETE_CUSTOMER));

        $this->assertStringContainsString('no longer valid', (string) $response->json('errors.0.message'));
        $this->assertSame(0, Order::query()->where('reference', 'insurance-lead-' . $lead->getId())->count());
    }

    public function testAnEditedQuoteNumberOrPlanIsRefused(): void
    {
        $lead = $this->selectedLead($this->sellablePlan());
        $lead->set(InsuranceCustomFieldEnum::QUOTE_NUMBER->value, 'OTHER-QUOTE');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('no longer valid');

        $this->contractAction($lead, ['customer' => self::COMPLETE_CUSTOMER])->execute();
    }

    public function testTheOrderTotalIsWhatTheInsurerSaysAtContractTimeNotWhatTheLeadHolds(): void
    {
        $lead = $this->selectedLead($this->sellablePlan());
        $this->customerProvider->total = 232.0;

        $order = $this->contractAction($lead, ['customer' => self::COMPLETE_CUSTOMER])->execute();

        $this->assertEquals(232.0, (float) $order->total_gross_amount);
        $this->assertEquals(232.0, (float) $order->get(InsuranceCustomFieldEnum::TOTAL->value));
        $this->assertEquals(116.0, (float) $lead->fresh()->get(InsuranceCustomFieldEnum::TOTAL->value));
    }

    public function testTheCustomerReachesTheInsurerBeforeTheOrderExists(): void
    {
        $lead = $this->selectedLead($this->sellablePlan());

        $order = $this->contractAction($lead, ['customer' => self::COMPLETE_CUSTOMER])->execute();

        $this->assertCount(1, $this->customerProvider->updates);
        $this->assertSame('Ana', $this->customerProvider->updates[0][1]['cliente']['nombre']);
        $this->assertSame(InsuranceStatusEnum::QUOTED->value, $order->get(InsuranceCustomFieldEnum::STATUS->value));
    }

    public function testAnInsurerRejectionOfTheCustomerCreatesNoOrder(): void
    {
        $lead = $this->selectedLead($this->sellablePlan());
        $this->customerProvider->rejectCustomerWith = 'Cedula invalida';

        $response = $this->graphQL(self::CONTRACT, $this->contract($lead, self::COMPLETE_CUSTOMER));

        $this->assertStringContainsString('Cedula invalida', (string) $response->json('errors.0.message'));
        $this->assertSame(0, Order::query()->where('reference', 'insurance-lead-' . $lead->getId())->count());
        $this->assertTrue($lead->fresh()->hasOpenLeadStatus());
    }

    public function testARetryAfterAHalfFinishedConversionReusesTheOrderAndFinishesTheLead(): void
    {
        $lead = $this->selectedLead($this->sellablePlan());
        $order = $this->contractAction($lead, ['customer' => self::COMPLETE_CUSTOMER])->execute();

        $lead->del(InsuranceCustomFieldEnum::ORDER_ID->value);
        $lead->open();
        $this->assertTrue($lead->fresh()->hasOpenLeadStatus());

        $retried = $this->contractAction($lead)->execute();

        $this->assertSame($order->getId(), $retried->getId());
        $this->assertSame(1, Order::query()->where('reference', 'insurance-lead-' . $lead->getId())->count());
        $this->assertSame(2, $retried->items()->count());
        $this->assertFalse($lead->fresh()->hasOpenLeadStatus());
        $this->assertSame($order->getId(), (int) $lead->fresh()->get(InsuranceCustomFieldEnum::ORDER_ID->value));
    }

    public function testTheOrderIsBornInsuranceTypedInTheQuotedStatusWithOneHistoryRow(): void
    {
        $lead = $this->selectedLead($this->sellablePlan());

        $order = $this->contractAction($lead, ['customer' => self::COMPLETE_CUSTOMER])->execute()->fresh();

        $this->assertSame(InsuranceOrderTypeEnum::INSURANCE->value, $order->orderType->name);
        $this->assertSame(InsuranceOrderStatusEnum::QUOTED->value, $order->orderStatus->slug);
        $this->assertNotSame(OrderStatusEnum::COMPLETED->value, $order->status);
        $this->assertSame(1, $order->orderTransitionHistory()->count());
        $this->assertTrue((bool) $order->orderTransitionHistory()->firstOrFail()->is_current);
    }

    public function testConversionIsRefusedBeforeAnythingHappensWhenThePipelineIsNotSeeded(): void
    {
        $lead = $this->selectedLead($this->sellablePlan());
        OrderStatus::query()->where('order_types_id', $this->insuranceOrderType->getId())->delete();

        $response = $this->graphQL(self::CONTRACT, $this->contract($lead, self::COMPLETE_CUSTOMER));

        $this->assertStringContainsString('not available right now', (string) $response->json('errors.0.message'));
        $this->assertSame(0, Order::query()->where('reference', 'insurance-lead-' . $lead->getId())->count());
        $this->assertCount(0, $this->customerProvider->updates);
        $this->assertTrue($lead->fresh()->hasOpenLeadStatus());
    }

    public function testConversionIsRefusedWhenTheOrderTypeDoesNotExistAtAll(): void
    {
        $lead = $this->selectedLead($this->sellablePlan());
        OrderStatus::query()->where('order_types_id', $this->insuranceOrderType->getId())->delete();
        $this->insuranceOrderType->delete();

        $this->expectException(ValidationException::class);

        $this->contractAction($lead, ['customer' => self::COMPLETE_CUSTOMER])->execute();
    }

    private function assertRefusedBeforeAnyOrderOrInsurerCall(Lead $lead, string $message): void
    {
        $reads = $this->customerProvider->reads;

        $response = $this->graphQL(self::CONTRACT, $this->contract($lead, self::COMPLETE_CUSTOMER));

        $this->assertStringContainsString($message, (string) $response->json('errors.0.message'), json_encode($response->json()));
        $this->assertSame(0, Order::query()->where('reference', 'insurance-lead-' . $lead->getId())->count());
        $this->assertCount(0, $this->customerProvider->updates);
        $this->assertSame($reads, $this->customerProvider->reads);
        $this->assertTrue($lead->fresh()->hasOpenLeadStatus());
    }

    public function testTheOrderCarriesTheVehicleAndThePlanAsItemsAndOnlyTheDataBlock(): void
    {
        $lead = $this->selectedLead($this->sellablePlan());

        $order = $this->contractAction($lead, ['customer' => self::COMPLETE_CUSTOMER])->execute()->fresh();
        $items = $order->items()->orderBy('id')->get();

        $this->assertCount(2, $items);
        $this->assertSame($this->vehicle()->variants()->firstOrFail()->getId(), (int) $items[0]->variant_id);
        $this->assertSame(InsuranceProductTypeEnum::VEHICLE->value, $items[0]->variant->product->productsType->slug);
        $this->assertEquals(0.0, (float) $items[0]->unit_price_gross_amount);
        $this->assertEquals(1.0, (float) $items[0]->quantity);
        $this->assertSame(InsuranceProductTypeEnum::PLAN->value, $items[1]->variant->product->productsType->slug);
        $this->assertEquals(116.0, (float) $items[1]->unit_price_gross_amount);
        $this->assertEquals(1.0, (float) $items[1]->quantity);
        $this->assertEquals(116.0, (float) $order->total_gross_amount);
        $this->assertEquals(0.0, (float) $order->tax_amount);
        $this->assertEquals(0.0, (float) $order->discount_amount);
        $this->assertEquals(0.0, (float) $order->shipping_price_gross_amount);
        $this->assertSame(['data'], array_keys($order->metadata));
        $this->assertSame(['quote_number', 'customer'], array_keys($order->metadata['data']));
    }

    public function testTheReaderUnderstandsTheContractBuiltOrderWithoutAnyLegacyBlock(): void
    {
        $lead = $this->selectedLead($this->sellablePlan());
        $order = $this->contractAction($lead, ['customer' => self::COMPLETE_CUSTOMER])->execute()->fresh();

        $data = new ReadInsuranceOrderAction($order)->execute();

        $this->assertFalse($data->legacy);
        $this->assertSame(self::PROVIDER, $data->provider);
        $this->assertSame('A-PA', $data->productCode);
        $this->assertSame('QN-1', $data->quoteNumber);
        $this->assertSame($this->vehicle()->variants()->firstOrFail()->getId(), $data->vehicle?->getId());
        $this->assertSame(InsuranceProductTypeEnum::PLAN->value, $data->plan?->product?->productsType?->slug);
        $this->assertSame('Ana', $data->customer?->toArray()['first_name']);
    }

    public function testTheInsurerRequestIsBuiltFromTheOrderData(): void
    {
        $lead = $this->selectedLead($this->sellablePlan());

        $this->contractAction($lead, ['customer' => self::COMPLETE_CUSTOMER])->execute();

        $this->assertCount(1, $this->customerProvider->updates);
        $this->assertSame(['cliente'], array_keys($this->customerProvider->updates[0][1]));
    }

    public function testTheVehicleProductIsStoredOnTheLeadAtSelectTime(): void
    {
        $lead = $this->selectedLead($this->sellablePlan());

        $this->assertSame($this->variantIdOf($this->vehicle()), (int) $lead->get(InsuranceCustomFieldEnum::VEHICLE_VARIANT_ID->value));
    }

    public function testASearchDoneWithTypedInDataCannotBeContracted(): void
    {
        $lead = $this->selectedLeadWithVehicle($this->sellablePlan(), null);

        $this->assertEmpty($lead->get(InsuranceCustomFieldEnum::VEHICLE_VARIANT_ID->value));
        $this->assertRefusedBeforeAnyOrderOrInsurerCall($lead, 'Register your vehicle first');
    }

    public function testAProductThatIsNotTypedVehicleIsRefused(): void
    {
        $plan = $this->sellablePlan();
        $accessory = $this->createVehicleProduct(auth()->user(), null, 'accessory');
        $lead = $this->selectedLeadWithVehicle($plan, $this->variantIdOf($accessory));

        $this->assertRefusedBeforeAnyOrderOrInsurerCall($lead, 'no longer available');
    }

    public function testAVehicleOwnedByAnotherUserIsRefused(): void
    {
        $plan = $this->sellablePlan();
        $foreign = $this->createVehicleProduct($this->createUser());
        $lead = $this->selectedLeadWithVehicle($plan, $this->variantIdOf($foreign));

        $this->assertRefusedBeforeAnyOrderOrInsurerCall($lead, 'does not belong to this account');
    }

    public function testAVehicleOfAnotherAppIsRefused(): void
    {
        $plan = $this->sellablePlan();
        $vehicle = $this->createVehicleProduct(auth()->user());
        $vehicle->apps_id = app(Apps::class)->getId() + 9999;
        $vehicle->saveQuietly();
        $lead = $this->selectedLeadWithVehicle($plan, $this->variantIdOf($vehicle));

        $this->assertRefusedBeforeAnyOrderOrInsurerCall($lead, 'does not belong to this account');
    }

    public function testADeletedVehicleIsRefused(): void
    {
        $lead = $this->selectedLead($this->sellablePlan());
        $this->vehicle()->is_deleted = 1;
        $this->vehicle()->saveQuietly();

        $this->assertRefusedBeforeAnyOrderOrInsurerCall($lead, 'no longer available');
    }

    public function testTheSignedVariantIsTheOneOrdered(): void
    {
        $first = $this->vehicle()->variants()->orderBy('id')->firstOrFail();
        $extra = $first->replicate(['uuid', 'slug']);
        $extra->sku = 'extra-' . uniqid();
        $extra->uuid = (string) Str::uuid();
        $extra->slug = Str::slug($extra->sku);
        $extra->saveQuietly();
        $lead = $this->selectedLeadWithVehicle($this->sellablePlan(), $extra->getId());

        $order = $this->contractAction($lead, ['customer' => self::COMPLETE_CUSTOMER])->execute()->fresh();

        $this->assertSame($extra->getId(), (int) $order->items()->orderBy('id')->firstOrFail()->variant_id);
    }

    public function testADeletedVariantIsRefused(): void
    {
        $lead = $this->selectedLead($this->sellablePlan());
        $this->vehicle()->variants()->update(['is_deleted' => 1]);

        $this->assertRefusedBeforeAnyOrderOrInsurerCall($lead, 'no longer available');
    }

    public function testEditingTheVehicleOfTheLeadInvalidatesTheSignedQuote(): void
    {
        $lead = $this->selectedLead($this->sellablePlan());
        $other = $this->createVehicleProduct(auth()->user());
        $lead->set(InsuranceCustomFieldEnum::VEHICLE_VARIANT_ID->value, $this->variantIdOf($other));

        $this->assertRefusedBeforeAnyOrderOrInsurerCall($lead, 'no longer valid');
    }

    public function testSelectingAgainWithoutAVehicleDropsTheOneStoredBefore(): void
    {
        $plan = $this->sellablePlan();
        $first = $this->selectedLead($plan);
        $second = $this->selectedLeadWithVehicle($plan, null, 'QN-2');

        $this->assertSame($first->getId(), $second->getId());
        $this->assertEmpty($second->fresh()->get(InsuranceCustomFieldEnum::VEHICLE_VARIANT_ID->value));
        $this->assertRefusedBeforeAnyOrderOrInsurerCall($second, 'Register your vehicle first');
    }

    public function testRetryingAfterTheVehicleWasDeletedStillReturnsTheExistingOrder(): void
    {
        $lead = $this->selectedLead($this->sellablePlan());
        $order = $this->contractAction($lead, ['customer' => self::COMPLETE_CUSTOMER])->execute();
        $this->vehicle()->is_deleted = 1;
        $this->vehicle()->saveQuietly();

        $retried = $this->contractAction($lead)->execute();

        $this->assertSame($order->getId(), $retried->getId());
    }
}
