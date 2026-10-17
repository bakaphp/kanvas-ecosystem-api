<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Baka\Http\SafeUrlFetcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\UniversalSeguros\Handlers\UniversalSegurosHandler;
use Kanvas\Connectors\UniversalSeguros\Providers\UniversalSegurosProvider;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Insurance\Actions\SyncInsuranceProductsAction;
use Kanvas\Insurance\Activities\AttachInsuranceQuoteActivity;
use Kanvas\Insurance\Activities\CancelInsuranceOrderActivity;
use Kanvas\Insurance\Activities\EmitInsurancePolicyActivity;
use Kanvas\Insurance\Activities\GenerateInsuranceCarnetActivity;
use Kanvas\Insurance\Activities\InvoiceInsurancePolicyActivity;
use Kanvas\Insurance\Activities\ReportInsurancePaymentActivity;
use Kanvas\Insurance\Activities\SendInsurancePolicyEmailActivity;
use Kanvas\Insurance\Activities\SyncInsurancePolicyActivity;
use Kanvas\Insurance\Activities\UploadInsuranceDocumentsActivity;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Enums\InsuranceOrderOriginEnum;
use Kanvas\Insurance\Enums\InsuranceOrderStatusEnum;
use Kanvas\Insurance\Enums\InsuranceOrderTypeEnum;
use Kanvas\Insurance\Enums\InsuranceStatusEnum;
use Kanvas\Insurance\Models\InsurancePlan;
use Kanvas\Insurance\Models\InsuranceVehicleModelMap;
use Kanvas\Insurance\Notifications\InsurancePolicyIssuedNotification;
use Kanvas\Insurance\Notifications\InsurancePolicyReminderNotification;
use Kanvas\Insurance\Services\VehicleKeyNormalizer;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Actions\CreatePaymentAction;
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
use Mockery;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\GraphQL\Souk\Traits\PaymentCases;
use Tests\Insurance\Concerns\BuildsSearchCatalog;
use Tests\Insurance\Concerns\CreatesVehicleProducts;
use Tests\Insurance\Concerns\SeedsInsuranceOrderPipeline;
use Tests\Insurance\Fakes\FakeUniversalServer;
use Tests\TestCase;

final class InsuranceJourneyTest extends TestCase
{
    use BuildsSearchCatalog;
    use CreatesVehicleProducts;
    use DatabaseTransactions;
    use HasIntegrationCompany;
    use InventoryCases;
    use PaymentCases;
    use SeedsInsuranceOrderPipeline;

    protected $connectionsToTransact = [null, 'commerce', 'insurance', 'crm', 'inventory', 'ecosystem', 'social', 'workflow'];

    private const array CUSTOMER = [
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
        'address_province' => 'Distrito Nacional',
        'address_municipality' => 'Santo Domingo de Guzman',
        'address_sector' => 'Naco',
        'address_building' => 'Torre 1',
        'address_street' => 'Calle 5',
        'politically_exposed' => false,
        'has_pep_relative' => false,
    ];

    private Apps $kanvasApp;

    private Users $kanvasUser;

    private FakeUniversalServer $universal;

    private InsurancePlan $plan;

    private Products $vehicle;

    private array $processorCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

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

        $server = $this->universal = new FakeUniversalServer();
        $this->app->bind(
            'insurance_provider.' . UniversalSegurosProvider::NAME,
            fn ($app, array $params): UniversalSegurosProvider => new UniversalSegurosProvider(
                app: $params['app'],
                company: $params['company'],
                service: $server,
            )
        );

        new SyncInsuranceProductsAction(
            new UniversalSegurosProvider($this->kanvasApp, $company, $server),
            $this->kanvasApp,
            $company
        )->execute();
        $company->set(InsuranceCustomFieldEnum::INSURER_COMPANY_ID->value, $company->getId());

        $insurer = $this->seedInsurer($company, UniversalSegurosProvider::NAME);
        $this->plan = $this->seedPlan($insurer, 'A-PA', ['commission_rate' => 10]);
        $this->vehicle = $this->registerVehicle($insurer->getId(), $insurer->apps_id);

        $this->createWarehouses((string) Regions::getDefault($company, $this->kanvasApp)->getId());
        $this->fakeProcessor();
        $this->createAttachRule();
    }

    public function testTheCartPathGoesFromSearchToAnInvoicedPolicy(): void
    {
        $entry = $this->searchEntry();
        $order = $this->createFromCart($entry);

        $this->assertQuotedWithItsOwnQuote($order, $entry['quote_number'], 'insurance-order-');
        $this->assertSame(2, $order->items()->count());
        $this->assertArrayNotHasKey('insurance', $order->metadata);

        $this->assertPolicyIsIssuedAndInvoiced($order);
    }

    public function testTheContractPathGoesFromSearchToAnInvoicedPolicy(): void
    {
        $entry = $this->searchEntry();
        $leadId = $this->graphQL(
            'mutation($input: SelectInsurancePlanInput!) { selectInsurancePlan(input: $input) { id } }',
            ['input' => ['plan_id' => $this->plan->getId(), 'quote_number' => $entry['quote_number']]]
        )->json('data.selectInsurancePlan.id');
        $this->assertNotNull($leadId);

        $response = $this->graphQL(
            'mutation($input: ContractInsurancePlanInput!) { contractInsurancePlan(input: $input) { id } }',
            ['input' => ['lead_id' => $leadId, 'customer' => self::CUSTOMER]]
        );
        $orderId = $response->json('data.contractInsurancePlan.id');
        $this->assertNotNull($orderId, json_encode($response->json()));

        $order = Order::findOrFail($orderId);
        $this->assertQuotedWithItsOwnQuote($order, $entry['quote_number'], 'insurance-lead-');
        $this->assertSame(2, $order->items()->count());
        $this->assertFalse(Lead::findOrFail($leadId)->hasOpenLeadStatus());

        $this->assertPolicyIsIssuedAndInvoiced($order);
    }

    public function testARefusedEmissionFailsTheOrderAndReleasesTheHold(): void
    {
        $order = $this->createFromCart($this->searchEntry());
        $this->universal->refuseEmission = true;

        $payment = $this->holdPayment($order);
        $this->runActivity(ReportInsurancePaymentActivity::class, $order);
        $this->uploadRegistration($order);
        $result = $this->runActivity(EmitInsurancePolicyActivity::class, $order);

        $order = $order->fresh();
        $this->assertTrue($result['paymentReleased'], json_encode($result));
        $this->assertSame(InsuranceStatusEnum::FAILED->value, $order->get(InsuranceCustomFieldEnum::STATUS->value));
        $this->assertSame(InsuranceOrderStatusEnum::FAILED->value, $order->orderStatus?->slug);
        $this->assertSame(PaymentStatusEnum::CANCELLED->value, $payment->fresh()->status);
        $this->assertSame([$payment->getId()], $this->processorCalls['void']);
        $this->assertArrayNotHasKey('capture', $this->processorCalls);
        $this->assertEmpty($order->get(InsuranceCustomFieldEnum::POLICY_NUMBER->value));
    }

    public function testEveryUnsafeOrderIsRefusedBeforeAnyMoneyOrInsurerWrite(): void
    {
        $entry = $this->searchEntry();

        $unbound = $this->createFromCart($entry, ['quote' => 'NOT-ISSUED-TO-ME']);
        $this->assertOrderFailed($unbound);

        $this->assertSame(0, $this->universal->callCount('updateQuote'));
        $this->assertSame(1, $this->universal->callCount('quote'));

        $leadId = $this->graphQL(
            'mutation($input: SelectInsurancePlanInput!) { selectInsurancePlan(input: $input) { id } }',
            ['input' => ['plan_id' => $this->plan->getId(), 'quote_number' => $entry['quote_number']]]
        )->json('data.selectInsurancePlan.id');

        $lead = Lead::findOrFail($leadId);
        $lead->set(InsuranceCustomFieldEnum::TOTAL->value, 1.0);

        $ordersBefore = Order::query()->fromApp($this->kanvasApp)->count();
        $response = $this->graphQL(
            'mutation($input: ContractInsurancePlanInput!) { contractInsurancePlan(input: $input) { id } }',
            ['input' => ['lead_id' => $leadId, 'customer' => self::CUSTOMER]]
        );

        $this->assertStringContainsString('no longer valid', (string) $response->json('errors.0.message'));
        $this->assertSame($ordersBefore, Order::query()->fromApp($this->kanvasApp)->count());
        $this->assertSame(0, $this->universal->callCount('updateQuote'));
        $this->assertSame(1, $this->universal->callCount('quote'));

        $underpriced = $this->createFromCart($entry, ['planPrice' => 1.0]);
        $this->assertOrderFailed($underpriced);
        $this->assertSame(0, $underpriced->payments()->count());

        $this->assertInsuranceOrdersOnlyAcceptAzul($underpriced);
    }

    private function assertInsuranceOrdersOnlyAcceptAzul(Order $order): void
    {
        $methods = [];

        foreach (['portal', 'azul'] as $processor) {
            $methods[$processor] = PaymentMethods::create([
                'apps_id' => $this->kanvasApp->getId(),
                'companies_id' => $order->companies_id,
                'users_id' => $this->kanvasUser->getId(),
                'payment_ending_numbers' => '1111',
                'payment_methods_brand' => 'visa',
                'expiration_date' => '2030-12',
                'processor' => $processor,
            ]);
        }

        foreach ([['payment_methods_id' => $methods['portal']->getId()], ['payment_method_type' => 'cash']] as $form) {
            try {
                new CreatePaymentAction($order, $this->kanvasUser)->execute($form + ['amount' => 1]);
                $this->fail('A payment outside the allowed processors was accepted');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('azul', $e->getMessage());
            }
        }

        $this->assertSame(0, $order->payments()->count());

        $payment = new CreatePaymentAction($order, $this->kanvasUser)->execute([
            'payment_methods_id' => $methods['azul']->getId(),
            'amount' => 1,
        ]);

        $this->assertSame('azul', $payment->processor);
    }

    public function testAnInsurerThatCannotEmitIsShownButNeverContracted(): void
    {
        $this->bindFakeProvider('basic_insurer');
        $basicPlan = $this->seedPlan(
            $this->seedInsurer($this->kanvasUser->getCurrentCompany(), 'basic_insurer'),
            'B-1'
        );

        $entries = collect($this->searchResult()['entries']);
        $this->assertTrue($entries->firstWhere('plan.id', (string) $this->plan->getId())['is_contractable']);

        $basic = $entries->firstWhere('plan.id', (string) $basicPlan->getId());
        $this->assertFalse($basic['is_contractable']);

        $leadId = $this->graphQL(
            'mutation($input: SelectInsurancePlanInput!) { selectInsurancePlan(input: $input) { id } }',
            ['input' => ['plan_id' => $basicPlan->getId(), 'quote_number' => $basic['quote_number']]]
        )->json('data.selectInsurancePlan.id');
        $this->assertNotNull($leadId);

        $ordersBefore = Order::query()->fromApp($this->kanvasApp)->count();
        $response = $this->graphQL(
            'mutation($input: ContractInsurancePlanInput!) { contractInsurancePlan(input: $input) { id } }',
            ['input' => ['lead_id' => $leadId, 'customer' => self::CUSTOMER]]
        );

        $this->assertStringContainsString('cannot be contracted', (string) $response->json('errors.0.message'));
        $this->assertSame($ordersBefore, Order::query()->fromApp($this->kanvasApp)->count());
        $this->assertTrue(Lead::findOrFail($leadId)->hasOpenLeadStatus());
    }

    public function testPolicyRemindersAreSentOnTheConfiguredDaysExactlyOnce(): void
    {
        $today = Carbon::today();
        $variantId = (string) $this->vehicle->variants()->firstOrFail()->getId();
        $register = function (string $number, Carbon $endsAt) use ($variantId): Order {
            $response = $this->graphQL(
                'mutation($input: RegisterInsurancePolicyInput!) { registerInsurancePolicy(input: $input) { id } }',
                ['input' => [
                    'vehicle_variant_id' => $variantId,
                    'insurer_name' => 'Seguros Locales',
                    'plan_name' => 'Full Plus',
                    'policy_number' => $number,
                    'starts_at' => $endsAt->copy()->subYear()->toDateString(),
                    'ends_at' => $endsAt->toDateString(),
                ]]
            );

            return Order::findOrFail($response->json('data.registerInsurancePolicy.id') ?? $this->fail(json_encode($response->json())));
        };
        $thirty = $register('P-30', $today->copy()->addDays(30));
        $seven = $register('P-07', $today->copy()->addDays(7));
        $register('P-05', $today->copy()->addDays(5));
        $register('P-EXPIRED', $today->copy()->subDay());
        $run = fn (Carbon $date): int => Artisan::call('kanvas:insurance-send-policy-reminders', [
            '--app' => $this->kanvasApp->getId(),
            '--date' => $date->toDateString(),
        ]);
        $user = $thirty->user;
        $sentDays = fn (): array => Notification::sent($user, InsurancePolicyReminderNotification::class)
            ->map(fn (InsurancePolicyReminderNotification $sent): array => [$sent->data['order_id'], $sent->daysLeft])
            ->sortBy(fn (array $sent): int => $sent[1])
            ->values()
            ->all();
        Notification::fake();

        $this->assertSame(0, $run($today));
        $this->assertSame([[$seven->getId(), 7], [$thirty->getId(), 30]], $sentDays());
        Notification::assertSentTo(
            $user,
            InsurancePolicyReminderNotification::class,
            fn (InsurancePolicyReminderNotification $sent): bool => $sent->data['order_id'] === $seven->getId()
                && $sent->data['vehicle_variant_id'] === (int) $variantId
                && $sent->data['insurer'] === 'Seguros Locales'
                && $sent->data['plan'] === 'Full Plus'
                && $sent->data['policyNumber'] === 'P-07'
                && $sent->data['expiryPhrase'] === 'vence en 7 dias'
                && $sent->channels() === ['mail', 'push', 'database']
                && str_contains($sent->getEmailContent(), 'P-07')
                && str_contains($sent->message(), 'Seguros Locales')
        );

        $run($today);
        $this->assertCount(2, $sentDays());

        $seven->set(InsuranceCustomFieldEnum::POLICY_ENDS_AT->value, $today->copy()->addDays(14)->toDateString());
        app()->instance(Apps::class, Apps::query()->where('id', '!=', $this->kanvasApp->getId())->firstOrFail());
        $run($today->copy()->addDays(7));

        $this->assertSame($this->kanvasApp->getId(), app(Apps::class)->getId());
        $this->assertSame(
            [[$seven->getId(), 7], [$seven->getId(), 7], [$thirty->getId(), 30]],
            $sentDays()
        );
        $run($today->copy()->addDays(7));
        $this->assertCount(3, $sentDays());
    }

    public function testAUserRegistersAPolicyBoughtElsewhere(): void
    {
        $variantId = (string) $this->vehicle->variants()->firstOrFail()->getId();
        $input = [
            'vehicle_variant_id' => $variantId,
            'insurer_name' => 'Seguros Locales',
            'plan_name' => 'Full Plus',
            'policy_number' => 'LC-2024 / 889',
            'starts_at' => '2025-01-01',
            'ends_at' => '2025-12-31',
        ];
        $mutation = 'mutation($input: RegisterInsurancePolicyInput!) { registerInsurancePolicy(input: $input) { id } }';

        $response = $this->graphQL($mutation, ['input' => $input]);
        $orderId = $response->json('data.registerInsurancePolicy.id');
        $this->assertNotNull($orderId, json_encode($response->json()));

        $order = Order::findOrFail($orderId);
        $this->assertRegisteredPolicy($order, $variantId);

        $listed = $this->graphQL(
            'query { orders(first: 25, orderType: { column: NAME, operator: EQ, value: "insurance" }) { data { id } } }'
        )->json('data.orders.data');
        $this->assertContains((string) $orderId, array_column($listed, 'id'));

        $repeated = $this->graphQL($mutation, ['input' => [...$input, 'policy_number' => 'lc2024889']]);
        $this->assertSame((string) $orderId, $repeated->json('data.registerInsurancePolicy.id'));
        $this->assertSame(1, Order::query()->where('reference', $order->reference)->count());

        $foreignVehicle = $this->createVehicleProduct(Users::query()->where('id', '>', 0)->where('id', '!=', $this->kanvasUser->getId())->firstOrFail());
        $foreign = $this->graphQL($mutation, ['input' => [
            ...$input,
            'vehicle_variant_id' => (string) $foreignVehicle->variants()->firstOrFail()->getId(),
        ]]);
        $this->assertStringContainsString('does not belong', (string) $foreign->json('errors.0.message'));

        $backwards = $this->graphQL($mutation, ['input' => [...$input, 'starts_at' => '2026-01-01']]);
        $this->assertStringContainsString('cannot be after', (string) $backwards->json('errors.0.message'));

        $this->assertPurchaseActivitiesLeaveItUntouched($order);
    }

    private function assertRegisteredPolicy(Order $order, string $variantId): void
    {
        $items = $order->allItems()->get();
        $this->assertCount(1, $items);
        $this->assertSame($variantId, (string) $items->first()->variant_id);
        $this->assertSame(0.0, (float) $items->first()->unit_price_net_amount);
        $this->assertSame(0.0, (float) $order->total_gross_amount);
        $this->assertSame(0, $order->payments()->count());
        $this->assertSame('completed', $order->status);
        $this->assertSame('fulfilled', $order->fulfillment_status);
        $this->assertSame(InsuranceOrderStatusEnum::POLICY_ACTIVE->value, $order->orderStatus?->slug);
        $this->assertSame(InsuranceStatusEnum::POLICY_ACTIVE->value, $order->get(InsuranceCustomFieldEnum::STATUS->value));
        $this->assertSame(InsuranceOrderOriginEnum::REGISTERED->value, $order->get(InsuranceCustomFieldEnum::ORIGIN->value));
        $this->assertSame('LC-2024 / 889', $order->get(InsuranceCustomFieldEnum::POLICY_NUMBER->value));
        $this->assertSame('2025-01-01', $order->get(InsuranceCustomFieldEnum::POLICY_STARTS_AT->value));
        $this->assertSame('2025-12-31', $order->get(InsuranceCustomFieldEnum::POLICY_ENDS_AT->value));
        $this->assertSame(
            [
                'insurer_name' => 'Seguros Locales',
                'plan_name' => 'Full Plus',
                'policy_number' => 'LC-2024 / 889',
                'starts_at' => '2025-01-01',
                'ends_at' => '2025-12-31',
            ],
            $order->metadata['data']
        );
        $this->assertArrayNotHasKey('insurance', $order->metadata);
        $this->assertSame(
            [InsuranceOrderStatusEnum::QUOTED->value, InsuranceOrderStatusEnum::POLICY_ACTIVE->value],
            $order->orderTransitionHistory()->orderBy('id')->get()->map(fn ($history) => $history->toStatus?->slug)->all()
        );
        $this->assertSame([], $this->universal->calls);
        $this->assertEmpty($order->get(InsuranceCustomFieldEnum::QUOTE_NUMBER->value));
    }

    private function assertPurchaseActivitiesLeaveItUntouched(Order $order): void
    {
        $before = $order->fresh()->only(['order_status_id', 'total_gross_amount', 'payment_status', 'metadata']);

        foreach ([
            AttachInsuranceQuoteActivity::class,
            ReportInsurancePaymentActivity::class,
            UploadInsuranceDocumentsActivity::class,
            EmitInsurancePolicyActivity::class,
            InvoiceInsurancePolicyActivity::class,
            SyncInsurancePolicyActivity::class,
            CancelInsuranceOrderActivity::class,
            GenerateInsuranceCarnetActivity::class,
            SendInsurancePolicyEmailActivity::class,
        ] as $activity) {
            $result = $this->runActivity($activity, $order);
            $this->assertSame('skipped', $result['status'] ?? null, $activity . ' ' . json_encode($result));
        }

        $order = $order->fresh();
        $this->assertSame($before, $order->only(['order_status_id', 'total_gross_amount', 'payment_status', 'metadata']));
        $this->assertSame(InsuranceStatusEnum::POLICY_ACTIVE->value, $order->get(InsuranceCustomFieldEnum::STATUS->value));
        $this->assertEmpty($order->get(InsuranceCustomFieldEnum::CARNET_URL->value));
        $this->assertSame([], $this->universal->calls);
    }

    private function assertPolicyIsIssuedAndInvoiced(Order $order): void
    {
        $payment = $this->holdPayment($order);

        $report = $this->runActivity(ReportInsurancePaymentActivity::class, $order);
        $this->assertSame('success', $report['status'], json_encode($report));
        $this->assertSame(
            InsuranceOrderStatusEnum::PAYMENT_REPORTED->value,
            $order->fresh()->orderStatus?->slug
        );

        $this->uploadRegistration($order);

        $emit = $this->runActivity(EmitInsurancePolicyActivity::class, $order);
        $this->assertSame('success', $emit['status'], json_encode($emit));
        $this->assertSame(FakeUniversalServer::POLICY, $emit['policyNumber']);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->fresh()->status);

        $invoice = $this->runActivity(InvoiceInsurancePolicyActivity::class, $order);
        $this->assertSame('success', $invoice['status'], json_encode($invoice));

        $order = $order->fresh();
        $this->assertSame(InsuranceStatusEnum::POLICY_ACTIVE->value, $order->get(InsuranceCustomFieldEnum::STATUS->value));
        $this->assertSame(InsuranceOrderStatusEnum::POLICY_ACTIVE->value, $order->orderStatus?->slug);
        $this->assertSame(FakeUniversalServer::POLICY, $order->get(InsuranceCustomFieldEnum::POLICY_NUMBER->value));
        $this->assertCount(1, $this->universal->invoices);
        $this->assertSame(FakeUniversalServer::POLICY, $this->universal->invoices[0]['noPoliza']);

        $this->assertCarnetIsGeneratedAndMailed($order);
    }

    private function assertCarnetIsGeneratedAndMailed(Order $order): void
    {
        Notification::fake();
        Storage::partialMock()->shouldReceive('build')->andReturn(Storage::fake('carnets'));
        $mutation = 'mutation($id: ID!) { sendInsurancePolicyEmail(order_id: $id) }';

        $early = $this->graphQL($mutation, ['id' => $order->getId()]);
        $this->assertStringContainsString('not ready yet', (string) $early->json('errors.0.message'));
        Notification::assertNothingSent();

        $carnet = $this->runActivity(GenerateInsuranceCarnetActivity::class, $order);
        $this->assertSame('success', $carnet['status'] ?? null, json_encode($carnet));

        $order = $order->fresh();
        $url = $order->get(InsuranceCustomFieldEnum::CARNET_URL->value);
        $this->assertNotEmpty($url);
        $this->assertSame(1, $this->carnetFilesCount($order));

        $again = $this->runActivity(GenerateInsuranceCarnetActivity::class, $order);
        $this->assertSame('skipped', $again['status'], json_encode($again));
        $this->assertSame(1, $this->carnetFilesCount($order->fresh()));

        $email = $this->runActivity(SendInsurancePolicyEmailActivity::class, $order);
        $this->assertSame('success', $email['status'], json_encode($email));
        Notification::assertSentToTimes($order->user, InsurancePolicyIssuedNotification::class, 1);
        Notification::assertSentTo(
            $order->user,
            InsurancePolicyIssuedNotification::class,
            fn (InsurancePolicyIssuedNotification $sent): bool => $sent->pathAttachment === [$url]
                && $sent->data['policyNumber'] === FakeUniversalServer::POLICY
                && $sent->data['insured'] === 'Ana Perez'
                && str_contains($sent->getEmailContent(), FakeUniversalServer::POLICY)
                && str_contains((string) $sent->getNotificationTitle(), 'carnet')
        );

        $repeated = $this->runActivity(SendInsurancePolicyEmailActivity::class, $order);
        $this->assertSame('skipped', $repeated['status'], json_encode($repeated));
        Notification::assertSentToTimes($order->user, InsurancePolicyIssuedNotification::class, 1);

        $resend = $this->graphQL($mutation, ['id' => $order->getId()]);
        $this->assertTrue($resend->json('data.sendInsurancePolicyEmail'), json_encode($resend->json()));
        Notification::assertSentToTimes($order->user, InsurancePolicyIssuedNotification::class, 2);
    }

    private function carnetFilesCount(Order $order): int
    {
        return $order->getFiles()->where('field_name', $order->order_number . '_carnet')->count();
    }

    private function assertQuotedWithItsOwnQuote(Order $order, string $searchedQuote, string $requestIdPrefix): void
    {
        $order = $order->fresh();
        $quote = (string) $order->get(InsuranceCustomFieldEnum::QUOTE_NUMBER->value);

        $this->assertSame(InsuranceStatusEnum::QUOTED->value, $order->get(InsuranceCustomFieldEnum::STATUS->value));
        $this->assertSame(InsuranceOrderStatusEnum::QUOTED->value, $order->orderStatus?->slug);
        $this->assertNotSame($searchedQuote, $quote, 'every order must own a quote that no other user shares');
        $this->assertStringStartsWith($requestIdPrefix, (string) $this->universal->quotes[$quote]['data']['requestId']);
        $this->assertSame('Ana', $this->universal->quotes[$quote]['data']['cliente']['nombre']);
        $this->assertSame('A', $this->universal->quotes[$quote]['data']['terminos']['fraccionamientoPago']);
        $this->assertSame(2, $this->universal->callCount('quote'));
    }

    private function assertOrderFailed(Order $order): void
    {
        $order = $order->fresh();

        $this->assertSame(InsuranceStatusEnum::FAILED->value, $order->get(InsuranceCustomFieldEnum::STATUS->value));
        $this->assertSame(InsuranceOrderStatusEnum::FAILED->value, $order->orderStatus?->slug);
        $this->assertNotEmpty($order->get(InsuranceCustomFieldEnum::FAILURE_REASON->value));
    }

    private function searchEntry(): array
    {
        $search = $this->searchResult();
        $this->assertCount(1, $search['entries']);
        $this->assertTrue($search['entries'][0]['is_contractable']);

        return $search['entries'][0];
    }

    private function searchResult(): array
    {
        $response = $this->graphQL(
            'query($input: CanonicalQuoteInput!) {
                insuranceSearch(input: $input) {
                    entries { plan { id variant_id } quote_number total is_contractable }
                    failures { reason message }
                }
            }',
            ['input' => [
                'category' => 'FULL',
                'vehicle' => ['variant_id' => $this->vehicle->variants()->firstOrFail()->getId()],
            ]]
        );

        $search = $response->json('data.insuranceSearch');
        $this->assertNotNull($search, json_encode($response->json()));
        $this->assertSame([], $search['failures'], json_encode($search['failures']));

        return $search;
    }

    private function createFromCart(array $entry, array $tamper = []): Order
    {
        $company = $this->kanvasUser->getCurrentCompany();

        $response = $this->graphQL(
            'mutation($input: OrderCartInput!) { createOrderFromCart(input: $input) { order { id } } }',
            ['input' => [
                'cartId' => 0,
                'customer' => ['email' => 'ana@example.com', 'phone' => '8095550100'],
                'order_type' => InsuranceOrderTypeEnum::INSURANCE->value,
                'items' => [
                    ['variant_id' => (string) $this->vehicle->variants()->firstOrFail()->getId(), 'quantity' => 1, 'price' => 0],
                    ['variant_id' => (string) $entry['plan']['variant_id'], 'quantity' => 1, 'price' => $tamper['planPrice'] ?? $entry['total']],
                ],
                'metadata' => ['data' => ['quote_number' => $tamper['quote'] ?? $entry['quote_number'], 'customer' => self::CUSTOMER]],
            ]],
            [],
            ['X-Kanvas-Location' => $company->branch->uuid, 'X-Kanvas-App' => $this->kanvasApp->key]
        );

        $id = $response->json('data.createOrderFromCart.order.id');
        $this->assertNotNull($id, json_encode($response->json()));

        $order = Order::findOrFail($id);
        $order->fireWorkflow(WorkflowEnum::CREATED->value, true, ['app' => $this->kanvasApp]);

        return $order->fresh();
    }

    private function registerVehicle(int $insurerId, int $appId): Products
    {
        $model = 'Corolla ' . uniqid();
        InsuranceVehicleModelMap::query()->create([
            'apps_id' => $appId,
            'insurers_id' => $insurerId,
            'plan_code' => '',
            'brand_key' => 'toyota',
            'model_key' => VehicleKeyNormalizer::normalize($model),
            'version_key' => '',
            'year' => 0,
            'external_model_id' => '77',
            'source' => 'manual',
            'active' => true,
        ]);

        return $this->createVehicleProduct($this->kanvasUser, [
            ['name' => 'brand', 'value' => 'Toyota'],
            ['name' => 'model', 'value' => $model],
            ['name' => 'year', 'value' => '2022'],
            ['name' => 'combustible', 'value' => 'gasoline_diesel'],
            ['name' => 'valor_estimado', 'value' => '1000000'],
            ['name' => 'vin', 'value' => '1FMCU0GX5DUA33591'],
            ['name' => 'plate', 'value' => 'G221924'],
        ]);
    }

    private function holdPayment(Order $order): Payments
    {
        return $order->payments()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $order->companies_id,
            'users_id' => $this->kanvasUser->getId(),
            'amount' => (float) $order->total_gross_amount,
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

    private function uploadRegistration(Order $order): void
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
        $order->addFile($file, 'registration');

        $upload = $this->runActivity(UploadInsuranceDocumentsActivity::class, $order);
        $this->assertSame('success', $upload['status'], json_encode($upload));
    }

    private function runActivity(string $class, Order $order): array
    {
        $activity = new $class(0, now()->toDateTimeString(), StoredWorkflow::make(), []);

        return $activity->execute($order->fresh(), $this->kanvasApp, []);
    }

    private function fakeProcessor(): void
    {
        $processor = Mockery::mock(PaymentProcessorInterface::class);
        $processor->shouldReceive('capture')->andReturnUsing(function (Payments $payment): CaptureResult {
            $this->processorCalls['capture'][] = $payment->getId();
            $payment->update(['status' => PaymentStatusEnum::PAID->value]);

            return new CaptureResult(true, 'captured', 'tx');
        });
        $processor->shouldReceive('void')->andReturnUsing(function (Payments $payment): VoidResult {
            $this->processorCalls['void'][] = $payment->getId();
            $payment->update(['status' => PaymentStatusEnum::CANCELLED->value]);

            return new VoidResult(true, 'voided', 'tx');
        });

        $this->app->bind('payment_processor.azul', fn () => $processor);
    }

    private function createAttachRule(): void
    {
        $company = $this->kanvasUser->getCurrentCompany();
        $ruleType = RuleType::firstOrCreate(['name' => WorkflowEnum::CREATED->value]);
        $systemModule = SystemModulesRepository::getByModelName(Order::class, $this->kanvasApp);

        $rule = Rule::factory()->create([
            'systems_modules_id' => $systemModule->getId(),
            'rules_types_id' => $ruleType->getId(),
            'companies_id' => $company->getId(),
            'apps_id' => $this->kanvasApp->getId(),
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
    }
}
