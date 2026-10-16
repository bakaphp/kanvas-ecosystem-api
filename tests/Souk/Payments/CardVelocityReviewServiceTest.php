<?php

declare(strict_types=1);

namespace Tests\Souk\Payments;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\PaymentLogs;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Services\CardVelocityReviewService;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class CardVelocityReviewServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'crm'];

    private Apps $kanvasApp;
    private Companies $company;
    private OrderTypes $orderType;
    private OrderTypes $otherOrderType;
    private Carbon $since;
    private Carbon $until;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = Companies::factory()->create(['users_id' => auth()->user()->getId()]);
        $this->orderType = $this->makeOrderType('paso_rapido');
        $this->otherOrderType = $this->makeOrderType('movipass');
        $this->since = now()->subDays(2);
        $this->until = now()->addDays(2);
    }

    protected function tearDown(): void
    {
        $this->company->deleteAllSettings();

        parent::tearDown();
    }

    public function testBlockedGroupsByUserWithDistinctCardsAndCounts(): void
    {
        $user = $this->createUser();
        $this->block($user, $this->orderType, '1111');
        $this->block($user, $this->orderType, '2222');

        $rows = $this->service()->blocked($this->since, $this->until, 20);

        $this->assertCount(1, $rows);
        $this->assertSame($user->getId(), $rows[0]['user']['id']);
        $this->assertFalse($rows[0]['is_corporate']);
        $this->assertSame(2, $rows[0]['block_count']);
        $this->assertSame(['visa:1111', 'visa:2222'], $rows[0]['distinct_cards']);
        $this->assertSame([$this->orderType->name], $rows[0]['order_types']);
    }

    public function testIsCorporateReflectsTheCompanySetting(): void
    {
        $this->company->set('is_corporate', '1');

        $user = $this->createUser();
        $this->block($user, $this->orderType, '1111');

        $rows = $this->service()->blocked($this->since, $this->until, 20);

        $this->assertTrue($rows[0]['is_corporate']);
    }

    public function testStillBannedReflectsTheLiveProfileNotTheBlockMetadata(): void
    {
        $bannedUser = $this->createUser();
        $this->block(
            $bannedUser,
            $this->orderType,
            '1111',
            banned: true,
        );

        $unbannedUser = $this->createUser();
        $this->block(
            $unbannedUser,
            $this->orderType,
            '1111',
            banned: true,
        );
        $profile = $unbannedUser->getAppProfile($this->kanvasApp);
        $profile->banned = 0;
        $profile->saveOrFail();

        $rows = collect($this->service()->blocked($this->since, $this->until, 20))
            ->keyBy(fn (array $row) => $row['user']['id']);

        $this->assertTrue($rows[$bannedUser->getId()]['banned_at_block']);
        $this->assertTrue($rows[$bannedUser->getId()]['user']['still_banned']);
        $this->assertTrue($rows[$unbannedUser->getId()]['banned_at_block']);
        $this->assertFalse($rows[$unbannedUser->getId()]['user']['still_banned']);
    }

    public function testPaidPaymentsInTheSameWindowAreReported(): void
    {
        $user = $this->createUser();
        $this->block($user, $this->orderType, '1111');
        $this->paidPayment($user, $this->orderType, 150.0);
        $this->paidPayment($user, $this->orderType, 50.0);

        $rows = $this->service()->blocked($this->since, $this->until, 20);

        $this->assertSame(2, $rows[0]['paid_count']);
        $this->assertSame(200.0, $rows[0]['paid_amount']);
    }

    public function testBlockedCanBeFilteredByOrderTypeId(): void
    {
        $userA = $this->createUser();
        $userB = $this->createUser();
        $this->block($userA, $this->orderType, '1111');
        $this->block($userB, $this->otherOrderType, '2222');

        $rows = $this->service()->blocked(
            $this->since,
            $this->until,
            20,
            [$this->orderType->getId()],
        );

        $this->assertCount(1, $rows);
        $this->assertSame($userA->getId(), $rows[0]['user']['id']);
    }

    public function testAtRiskIncludesUsersAboveDeclineThresholdAndBelowPaidRatio(): void
    {
        $risky = $this->createUser();
        $this->failedPayment($risky, $this->orderType, '1111');
        $this->failedPayment($risky, $this->orderType, '2222');

        $rows = $this->service()->atRisk(
            $this->since,
            $this->until,
            2,
            0.5,
            20,
        );

        $this->assertCount(1, $rows);
        $this->assertSame($risky->getId(), $rows[0]['user']['id']);
        $this->assertSame(2, $rows[0]['failed_count']);
        $this->assertSame(0.0, $rows[0]['paid_ratio']);
    }

    public function testAtRiskExcludesUsersAboveThePaidRatio(): void
    {
        $healthy = $this->createUser();
        $this->failedPayment($healthy, $this->orderType, '1111');
        $this->failedPayment($healthy, $this->orderType, '2222');
        $this->paidPayment($healthy, $this->orderType, 100.0);
        $this->paidPayment($healthy, $this->orderType, 100.0);
        $this->paidPayment($healthy, $this->orderType, 100.0);

        $rows = $this->service()->atRisk(
            $this->since,
            $this->until,
            2,
            0.5,
            20,
        );

        $this->assertSame([], $rows);
    }

    public function testAtRiskCanBeFilteredByOrderTypeId(): void
    {
        $matching = $this->createUser();
        $this->failedPayment($matching, $this->orderType, '1111');
        $this->failedPayment($matching, $this->orderType, '2222');

        $other = $this->createUser();
        $this->failedPayment($other, $this->otherOrderType, '3333');
        $this->failedPayment($other, $this->otherOrderType, '4444');

        $rows = $this->service()->atRisk(
            $this->since,
            $this->until,
            2,
            1.0,
            20,
            [$this->orderType->getId()],
        );

        $this->assertCount(1, $rows);
        $this->assertSame($matching->getId(), $rows[0]['user']['id']);
    }

    public function testAtRiskPaidRatioIgnoresPaymentsOfOtherOrderTypesWhenFiltered(): void
    {
        $user = $this->createUser();
        $this->failedPayment($user, $this->orderType, '1111');
        $this->failedPayment($user, $this->orderType, '2222');
        $this->paidPayment($user, $this->otherOrderType, 50.0);
        $this->paidPayment($user, $this->otherOrderType, 50.0);

        $rows = $this->service()->atRisk(
            $this->since,
            $this->until,
            2,
            0.1,
            20,
            [$this->orderType->getId()],
        );

        $this->assertCount(1, $rows);
        $this->assertSame(0, $rows[0]['paid_count']);
        $this->assertSame(0.0, $rows[0]['paid_ratio']);
    }

    public function testStuck3dsCountsOnlyWhenOlderThan30Minutes(): void
    {
        $stale = $this->createUser();
        $this->stuckPayment(
            $stale,
            $this->orderType,
            PaymentStatusEnum::WAITING_DEVICE_DATA,
            now()->subMinutes(45),
        );
        $this->stuckPayment(
            $stale,
            $this->orderType,
            PaymentStatusEnum::PENDING_AUTHORIZATION,
            now()->subMinutes(31),
        );

        $recent = $this->createUser();
        $this->stuckPayment(
            $recent,
            $this->orderType,
            PaymentStatusEnum::WAITING_DEVICE_DATA,
            now()->subMinutes(5),
        );
        $this->stuckPayment(
            $recent,
            $this->orderType,
            PaymentStatusEnum::PENDING_AUTHORIZATION,
            now()->subMinutes(10),
        );

        $rows = collect($this->service()->atRisk(
            $this->since,
            $this->until,
            2,
            1.0,
            20,
        ))->keyBy(fn (array $row) => $row['user']['id']);

        $this->assertTrue($rows->has($stale->getId()));
        $this->assertSame(2, $rows[$stale->getId()]['failed_count']);
        $this->assertFalse($rows->has($recent->getId()));
    }

    public function testAtRiskCountsOnlyThePlainFailureWhenTheUsersBlockPredatesTheWindow(): void
    {
        $user = $this->createUser();
        $oldBlock = $this->payment(
            $user,
            $this->orderType,
            '1111',
            PaymentStatusEnum::FAILED->value,
            now()->subDays(10),
        );
        $oldBlock->addLog(Payments::CARD_VELOCITY_BLOCKED_EVENT, [
            'order_type' => $this->orderType->name,
            'cards' => 1,
            'banned' => false,
        ]);
        PaymentLogs::where('payments_id', $oldBlock->getId())->update(['created_at' => now()->subDays(10)]);

        $this->failedPayment($user, $this->orderType, '2222');

        $rows = collect($this->service()->atRisk(
            $this->since,
            $this->until,
            1,
            1.0,
            20,
        ))->keyBy(fn (array $row) => $row['user']['id']);

        $this->assertTrue($rows->has($user->getId()));
        $this->assertSame(1, $rows[$user->getId()]['failed_count']);
    }

    public function testBlockedUsersAreExcludedFromAtRiskEvenWithOtherFailures(): void
    {
        $user = $this->createUser();
        $this->block($user, $this->orderType, '1111');
        $this->failedPayment($user, $this->orderType, '2222');
        $this->failedPayment($user, $this->orderType, '3333');

        $rows = $this->service()->atRisk(
            $this->since,
            $this->until,
            2,
            1.0,
            20,
        );

        $this->assertSame([], $rows);
    }

    public function testTenantIsolationExcludesOtherCompanyAndOtherApp(): void
    {
        $otherCompany = Companies::factory()->create(['users_id' => auth()->user()->getId()]);
        $otherCompanyUser = $this->createUser();
        $this->block(
            $otherCompanyUser,
            $this->orderType,
            '9999',
            companiesId: $otherCompany->getId(),
        );
        $this->payment(
            $otherCompanyUser,
            $this->orderType,
            '9998',
            PaymentStatusEnum::FAILED->value,
            companiesId: $otherCompany->getId(),
        );

        $otherAppId = $this->kanvasApp->getId() + 999999;
        $otherAppUser = $this->createUser();
        $this->block(
            $otherAppUser,
            $this->orderType,
            '8888',
            appsId: $otherAppId,
        );
        $this->payment(
            $otherAppUser,
            $this->orderType,
            '8887',
            PaymentStatusEnum::FAILED->value,
            appsId: $otherAppId,
        );

        $this->assertSame([], $this->service()->blocked($this->since, $this->until, 20));
        $this->assertSame([], $this->service()->atRisk(
            $this->since,
            $this->until,
            1,
            1.0,
            20,
        ));
    }

    private function service(): CardVelocityReviewService
    {
        return new CardVelocityReviewService($this->kanvasApp, $this->company);
    }

    private function makeOrderType(string $name): OrderTypes
    {
        $type = new OrderTypes();
        $type->apps_id = $this->kanvasApp->getId();
        $type->companies_id = $this->company->getId();
        $type->name = $name;
        $type->saveOrFail();

        return $type;
    }

    private function order(
        Users $user,
        OrderTypes $type,
        ?int $appsId,
        ?int $companiesId,
    ): Order {
        return Order::factory()
            ->withAppId($appsId ?? $this->kanvasApp->getId())
            ->withCompanyId($companiesId ?? $this->company->getId())
            ->withUserId($user->getId())
            ->create(['order_types_id' => $type->getId()]);
    }

    private function payment(
        Users $user,
        OrderTypes $type,
        string $lastFour,
        string $status,
        ?Carbon $createdAt = null,
        ?int $appsId = null,
        ?int $companiesId = null,
    ): Payments {
        $order = $this->order(
            $user,
            $type,
            $appsId,
            $companiesId,
        );

        $payment = new Payments();
        $payment->apps_id = $appsId ?? $this->kanvasApp->getId();
        $payment->companies_id = $companiesId ?? $this->company->getId();
        $payment->users_id = $user->getId();
        $payment->payment_methods_id = 1;
        $payment->payable_id = $order->getId();
        $payment->payable_type = Order::class;
        $payment->payment_date = now()->toDateString();
        $payment->payment_method = 'card';
        $payment->payment_method_brand = 'visa';
        $payment->payment_method_last_four = $lastFour;
        $payment->concept = 'Payment ' . $order->reference;
        $payment->amount = 100.0;
        $payment->currency = 'DOP';
        $payment->status = $status;
        $payment->is_deleted = false;

        if ($createdAt) {
            $payment->created_at = $createdAt;
        }

        $payment->saveOrFail();

        return $payment;
    }

    private function block(
        Users $user,
        OrderTypes $type,
        string $lastFour,
        bool $banned = false,
        ?int $appsId = null,
        ?int $companiesId = null,
    ): Payments {
        $payment = $this->payment(
            $user,
            $type,
            $lastFour,
            PaymentStatusEnum::FAILED->value,
            appsId: $appsId,
            companiesId: $companiesId,
        );
        $payment->addLog(Payments::CARD_VELOCITY_BLOCKED_EVENT, [
            'order_type' => $type->name,
            'cards' => 1,
            'banned' => $banned,
        ]);

        if ($banned) {
            $profile = $user->getAppProfile(app(Apps::class));
            $profile->banned = 1;
            $profile->saveOrFail();
        }

        return $payment;
    }

    private function paidPayment(Users $user, OrderTypes $type, float $amount): Payments
    {
        $payment = $this->payment(
            $user,
            $type,
            '4242',
            PaymentStatusEnum::PAID->value,
        );
        $payment->amount = $amount;
        $payment->saveOrFail();

        return $payment;
    }

    private function failedPayment(Users $user, OrderTypes $type, string $lastFour): Payments
    {
        return $this->payment(
            $user,
            $type,
            $lastFour,
            PaymentStatusEnum::FAILED->value,
        );
    }

    private function stuckPayment(
        Users $user,
        OrderTypes $type,
        PaymentStatusEnum $status,
        Carbon $createdAt,
    ): Payments {
        return $this->payment(
            $user,
            $type,
            '5555',
            $status->value,
            $createdAt,
        );
    }
}
