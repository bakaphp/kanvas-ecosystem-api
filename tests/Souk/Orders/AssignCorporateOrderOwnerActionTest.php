<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Internal\Handlers\InternalHandler;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\PasoRapido\Enums\CustomFieldEnum as PasoRapidoCustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Actions\AssignCorporateOrderOwnerAction;
use Kanvas\Souk\Orders\Activities\SyncCorporateOrdersActivity;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

final class AssignCorporateOrderOwnerActionTest extends TestCase
{
    use HasIntegrationCompany;

    private const string WARNING = 'Corporate order creator rejected, users_id left unchanged';

    private Apps $kanvasApp;
    private Users $systemUser;
    private Companies $corporateCompany;
    private Users $owner;
    private Users $creator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $this->systemUser = $user;

        $this->owner = Users::factory()->create();
        $this->corporateCompany = Companies::factory()->create(['users_id' => $this->owner->getId()]);
        $this->corporateCompany->associateApp($this->kanvasApp);
        $this->corporateCompany->associateUserApp($this->owner, $this->kanvasApp, 1);

        $this->creator = Users::factory()->create();
        $this->corporateCompany->associateUserApp($this->creator, $this->kanvasApp, 1);
    }

    public function testReassignsCorporateOrderToItsCreator(): void
    {
        $order = $this->makeOrder(['data' => $this->corporateData($this->creator->getId())]);

        $reassigned = new AssignCorporateOrderOwnerAction($order)->execute();

        $this->assertNotNull($reassigned);
        $this->assertSame($this->creator->getId(), (int) $reassigned->users_id);
        $this->assertSame(
            $this->systemUser->getId(),
            (int) $reassigned->metadata['data'][AssignCorporateOrderOwnerAction::ACTOR_METADATA_KEY]
        );
    }

    public function testReassignsProcessedPasoRapidoCardOrderToItsCreator(): void
    {
        $order = $this->makeProcessedPasoRapidoCardOrder((string) $this->creator->getId());
        $peopleId = $order->people_id;
        $companyId = $order->companies_id;

        new AssignCorporateOrderOwnerAction($order)->execute();

        $order->refresh();
        $this->assertSame($this->creator->getId(), (int) $order->users_id);
        $this->assertSame(
            $this->systemUser->getId(),
            (int) $order->metadata['data'][AssignCorporateOrderOwnerAction::ACTOR_METADATA_KEY]
        );
        $this->assertSame($peopleId, $order->people_id);
        $this->assertSame($companyId, $order->companies_id);
    }

    public function testReassignsProcessedPasoRapidoWalletBulkOrderToItsCreator(): void
    {
        $order = $this->makeOrder([
            'user_company_id' => $this->corporateCompany->getId(),
            'data' => ['is_bulk_recharge' => true, 'created_by_user_id' => (string) $this->creator->getId()],
            'corporate_recharge_results' => [
                '941001' => ['status' => 'success', 'amount' => 500.0],
                '941002' => ['status' => 'failed', 'amount' => 250.0, 'reversed' => true],
            ],
        ], OrderTypeEnum::PASO_RAPIDO->value);

        new AssignCorporateOrderOwnerAction($order)->execute();

        $this->assertSame($this->creator->getId(), (int) $order->refresh()->users_id);
    }

    public function testDoesNotFallBackToCompanyOwnerWhenCreatorIsFromAnotherCompany(): void
    {
        Log::spy();
        $outsider = Users::factory()->create();
        $order = $this->makeOrder(['data' => $this->corporateData($outsider->getId())]);

        $this->assertNull(new AssignCorporateOrderOwnerAction($order)->execute());

        $order->refresh();
        $this->assertSame($this->systemUser->getId(), (int) $order->users_id);
        $this->assertNotSame($this->owner->getId(), (int) $order->users_id);
        $this->assertArrayNotHasKey(AssignCorporateOrderOwnerAction::ACTOR_METADATA_KEY, $order->metadata['data']);
        $this->assertWarningLoggedFor($order);
    }

    public static function invalidCreatorValues(): array
    {
        return [
            'missing' => [null],
            'zero' => ['0'],
            'zero int' => [0],
            'empty' => [''],
            'not numeric' => ['abc'],
            'numeric prefix' => ['12abc'],
            'negative' => ['-5'],
            'float' => ['12.5'],
            'unknown user' => ['999999999'],
        ];
    }

    #[DataProvider('invalidCreatorValues')]
    public function testLeavesOrderUntouchedAndLogsWarningForInvalidCreator(mixed $creatorId): void
    {
        Log::spy();
        $order = $this->makeProcessedPasoRapidoCardOrder($creatorId);

        $this->assertNull(new AssignCorporateOrderOwnerAction($order)->execute());

        $this->assertSame($this->systemUser->getId(), (int) $order->refresh()->users_id);
        $this->assertWarningLoggedFor($order);
    }

    public function testLeavesFailedPasoRapidoCardRechargeUntouched(): void
    {
        $order = $this->makeProcessedPasoRapidoCardOrder((string) $this->creator->getId());
        $order->set(PasoRapidoCustomFieldEnum::PASO_RAPIDO_PAYMENT_STATUS->value, PaymentStatusEnum::FAILED->value);

        $this->assertNull(new AssignCorporateOrderOwnerAction($order)->execute());
        $this->assertSame($this->systemUser->getId(), (int) $order->refresh()->users_id);
    }

    public function testLeavesUnpaidPasoRapidoCardOrderUntouched(): void
    {
        $order = $this->makeProcessedPasoRapidoCardOrder((string) $this->creator->getId());
        $order->payment_status = PaymentStatusEnum::PROCESSING->value;
        $order->saveQuietly();

        $this->assertNull(new AssignCorporateOrderOwnerAction($order)->execute());
        $this->assertSame($this->systemUser->getId(), (int) $order->refresh()->users_id);
    }

    public function testLeavesFullyFailedPasoRapidoBulkRechargeUntouched(): void
    {
        $order = $this->makeOrder([
            'user_company_id' => $this->corporateCompany->getId(),
            'data' => ['is_bulk_recharge' => true, 'created_by_user_id' => (string) $this->creator->getId()],
            'corporate_recharge_results' => [
                '941001' => ['status' => 'failed', 'amount' => 500.0, 'reversed' => true],
            ],
        ], OrderTypeEnum::PASO_RAPIDO->value, 'refunded');

        $this->assertNull(new AssignCorporateOrderOwnerAction($order)->execute());
        $this->assertSame($this->systemUser->getId(), (int) $order->refresh()->users_id);
    }

    public function testLeavesPasoRapidoBulkOrderNotYetRechargedUntouched(): void
    {
        $order = $this->makeOrder([
            'user_company_id' => $this->corporateCompany->getId(),
            'data' => ['is_bulk_recharge' => true, 'created_by_user_id' => (string) $this->creator->getId()],
        ], OrderTypeEnum::PASO_RAPIDO->value);

        $this->assertNull(new AssignCorporateOrderOwnerAction($order)->execute());
        $this->assertSame($this->systemUser->getId(), (int) $order->refresh()->users_id);
    }

    public function testIgnoresNonCorporateOrdersWithoutWarning(): void
    {
        Log::spy();
        $order = $this->makeOrder([]);

        $this->assertNull(new AssignCorporateOrderOwnerAction($order)->execute());

        $this->assertSame($this->systemUser->getId(), (int) $order->refresh()->users_id);
        Log::shouldNotHaveReceived('warning', [self::WARNING, Mockery::any()]);
    }

    public function testIsIdempotentAndDoesNotOverwriteTheCapturedActorOnReRun(): void
    {
        $order = $this->makeOrder(['data' => $this->corporateData($this->creator->getId())]);

        new AssignCorporateOrderOwnerAction($order)->execute();
        $secondRun = new AssignCorporateOrderOwnerAction($order->refresh())->execute();

        $this->assertNull($secondRun, 'Already-owned order must be a no-op.');
        $order->refresh();
        $this->assertSame($this->creator->getId(), (int) $order->users_id);
        $this->assertSame(
            $this->systemUser->getId(),
            (int) $order->metadata['data'][AssignCorporateOrderOwnerAction::ACTOR_METADATA_KEY]
        );
    }

    public function testActivityReassignsProcessedPasoRapidoOrder(): void
    {
        $this->setIntegration(
            $this->kanvasApp,
            IntegrationsEnum::INTERNAL,
            InternalHandler::class,
            $this->systemUser->getCurrentCompany(),
            $this->systemUser,
        );

        $order = $this->makeProcessedPasoRapidoCardOrder((string) $this->creator->getId());

        $result = new SyncCorporateOrdersActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            [],
        )->execute($order, $this->kanvasApp, []);

        $this->assertTrue($result['result'] ?? false, json_encode($result));
        $this->assertSame($this->creator->getId(), $result['new_user_id']);
        $this->assertSame($this->systemUser->getId(), $result['actor_user_id']);
    }

    private function assertWarningLoggedFor(Order $order): void
    {
        Log::shouldHaveReceived('warning')
            ->with(self::WARNING, Mockery::on(fn (array $context) => $context['order_id'] === $order->getId()))
            ->once();
    }

    private function corporateData(mixed $creatorId): array
    {
        $data = ['user_company_id' => $this->corporateCompany->getId()];

        if ($creatorId !== null) {
            $data[AssignCorporateOrderOwnerAction::CREATOR_METADATA_KEY] = $creatorId;
        }

        return $data;
    }

    private function makeProcessedPasoRapidoCardOrder(mixed $creatorId): Order
    {
        $order = $this->makeOrder(
            ['data' => ['paso_rapido_tag' => '941001', ...$this->corporateData($creatorId)]],
            OrderTypeEnum::PASO_RAPIDO->value,
        );
        $order->set(PasoRapidoCustomFieldEnum::PASO_RAPIDO_PAYMENT_STATUS->value, PaymentStatusEnum::PAID->value);

        return $order;
    }

    private function makeOrder(array $metadata, ?string $orderType = null, string $paymentStatus = 'paid'): Order
    {
        $company = $this->systemUser->getCurrentCompany();

        $people = People::factory()
            ->withUserId($this->systemUser->getId())
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($company->getId())
            ->create();

        $order = Order::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($company->getId())
            ->withUserId($this->systemUser->getId())
            ->withPeopleId($people->getId())
            ->create([
                'metadata' => $metadata,
                'payment_status' => $paymentStatus,
            ]);

        if ($orderType !== null) {
            $order->setOrderType($orderType);
        }

        return $order->refresh();
    }
}
