<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Insurance\Actions\RecordInsuranceStatusAction;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Enums\InsuranceOrderStatusEnum;
use Kanvas\Insurance\Enums\InsuranceOrderTypeEnum;
use Kanvas\Insurance\Enums\InsuranceStatusEnum;
use Kanvas\Insurance\Services\InsuranceOrderPipeline;
use Kanvas\Souk\Orders\Actions\TransitionOrderStateAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderStatusTransitions;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Actions\EnforceCardVelocityLimitAction;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Users\Models\Users;
use Tests\Insurance\Concerns\SeedsInsuranceOrderPipeline;
use Tests\TestCase;

class InsuranceOrderTypeTest extends TestCase
{
    use DatabaseTransactions;
    use SeedsInsuranceOrderPipeline;

    protected $connectionsToTransact = [null, 'commerce', 'insurance', 'crm', 'inventory', 'ecosystem', 'social'];

    private Apps $kanvasApp;

    private Users $kanvasUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->kanvasUser = auth()->user();
    }

    private function order(bool $typed = true, bool $withInitialState = true): Order
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
            ->create();

        if ($typed) {
            $order->setOrderType(InsuranceOrderTypeEnum::INSURANCE->value);
        }

        if ($typed && $withInitialState) {
            new TransitionOrderStateAction($order, $this->kanvasUser, $order->orderStatus)->setInitialState();
        }

        return $order;
    }

    private function record(Order $order, InsuranceStatusEnum $status): void
    {
        new RecordInsuranceStatusAction($order, $status)->execute();
    }

    private function orderStatusSlug(Order $order): ?string
    {
        return $order->fresh()->orderStatus?->slug;
    }

    public function testSetupCreatesTheTypeItsStatusesAndTransitions(): void
    {
        $orderType = $this->seedInsuranceOrderPipeline();

        $statuses = OrderStatus::query()->where('order_types_id', $orderType->getId())->get();

        $this->assertSame(InsuranceOrderTypeEnum::INSURANCE->value, $orderType->name);
        $this->assertEqualsCanonicalizing(
            array_map(fn (InsuranceOrderStatusEnum $status): string => $status->value, InsuranceOrderStatusEnum::cases()),
            $statuses->pluck('slug')->all()
        );
        $this->assertSame(['quoted'], $statuses->where('is_default', true)->pluck('slug')->values()->all());
        $this->assertEqualsCanonicalizing(
            ['policy_active', 'cancelled', 'failed'],
            $statuses->where('is_final', true)->pluck('slug')->values()->all()
        );
        $this->assertSame(count(InsuranceOrderStatusEnum::cases()), $orderType->fresh()->total_statuses);
        $this->assertSame(
            array_sum(array_map(fn (InsuranceOrderStatusEnum $status): int => count($status->transitionsTo()), InsuranceOrderStatusEnum::cases())),
            OrderStatusTransitions::query()->where('order_types_id', $orderType->getId())->count()
        );
        $this->assertTrue(InsuranceOrderPipeline::isSeeded($this->kanvasApp));
    }

    public function testSetupCommandIsIdempotent(): void
    {
        $this->artisan('kanvas:insurance-setup-order-type', ['app_id' => $this->kanvasApp->getId()])->assertSuccessful();
        $first = OrderTypes::query()->where('apps_id', $this->kanvasApp->getId())->where('name', 'insurance')->firstOrFail();
        $statusIds = OrderStatus::query()->where('order_types_id', $first->getId())->orderBy('id')->pluck('id')->all();
        $transitions = OrderStatusTransitions::query()->where('order_types_id', $first->getId())->count();

        $this->artisan('kanvas:insurance-setup-order-type', ['app_id' => $this->kanvasApp->getId()])->assertSuccessful();

        $this->assertSame(
            1,
            OrderTypes::query()->where('apps_id', $this->kanvasApp->getId())->where('name', 'insurance')->count()
        );
        $this->assertSame($statusIds, OrderStatus::query()->where('order_types_id', $first->getId())->orderBy('id')->pluck('id')->all());
        $this->assertSame($transitions, OrderStatusTransitions::query()->where('order_types_id', $first->getId())->count());
    }

    public function testThePipelineIsNotSeededUntilEveryStatusExists(): void
    {
        $orderType = $this->seedInsuranceOrderPipeline();
        $this->assertTrue(InsuranceOrderPipeline::isSeeded($this->kanvasApp));

        OrderStatus::query()->where('order_types_id', $orderType->getId())->where('slug', 'failed')->delete();

        $this->assertFalse(InsuranceOrderPipeline::isSeeded($this->kanvasApp));
    }

    public function testEachInsuranceStatusMovesTheOrderAlongTheHappyPath(): void
    {
        $this->seedInsuranceOrderPipeline();
        $order = $this->order();
        $this->assertSame('quoted', $this->orderStatusSlug($order));

        $this->record($order, InsuranceStatusEnum::DOCUMENTS_UPLOADED);
        $this->assertSame('documents_uploaded', $this->orderStatusSlug($order));

        $this->record($order, InsuranceStatusEnum::AWAITING_PAYMENT);
        $this->assertSame('awaiting_payment', $this->orderStatusSlug($order));

        $this->record($order, InsuranceStatusEnum::PAID);
        $this->assertSame('payment_reported', $this->orderStatusSlug($order));

        $this->record($order, InsuranceStatusEnum::EMITTED);
        $this->assertSame('emitted', $this->orderStatusSlug($order));

        $this->record($order, InsuranceStatusEnum::POLICY_ACTIVE);
        $this->assertSame('policy_active', $this->orderStatusSlug($order));
        $this->assertSame(
            InsuranceStatusEnum::POLICY_ACTIVE->value,
            $order->fresh()->get(InsuranceCustomFieldEnum::STATUS->value)
        );
        $this->assertSame(6, $order->orderTransitionHistory()->count());
        $this->assertSame(1, $order->orderTransitionHistory()->where('is_current', true)->count());
    }

    public function testAPaymentThatIsReportedButNotCapturedDoesNotMarkTheOrderPaid(): void
    {
        $this->seedInsuranceOrderPipeline();
        $order = $this->order();

        $this->record($order, InsuranceStatusEnum::PAID);

        $this->assertNotSame(PaymentStatusEnum::PAID->value, $order->fresh()->payment_status);
    }

    public function testAFailureAfterThePaymentWasReportedMarksTheOrderFailed(): void
    {
        $this->seedInsuranceOrderPipeline();
        $order = $this->order();
        $this->record($order, InsuranceStatusEnum::PAID);

        $this->record($order, InsuranceStatusEnum::FAILED);

        $this->assertSame('failed', $this->orderStatusSlug($order));
    }

    public function testCancellingAQuotedOrderMarksItCancelled(): void
    {
        $this->seedInsuranceOrderPipeline();
        $order = $this->order();

        $this->record($order, InsuranceStatusEnum::CANCELLED);

        $this->assertSame('cancelled', $this->orderStatusSlug($order));
    }

    public function testATransitionThePipelineDoesNotAllowKeepsTheOrderStatusAndStillRecordsTheInsuranceStatus(): void
    {
        $this->seedInsuranceOrderPipeline();
        $order = $this->order();
        $this->record($order, InsuranceStatusEnum::PAID);
        $this->record($order, InsuranceStatusEnum::POLICY_ACTIVE);
        $this->assertSame('policy_active', $this->orderStatusSlug($order));

        $this->record($order, InsuranceStatusEnum::CANCELLED);

        $this->assertSame('policy_active', $this->orderStatusSlug($order));
        $this->assertSame(
            InsuranceStatusEnum::CANCELLED->value,
            $order->fresh()->get(InsuranceCustomFieldEnum::STATUS->value)
        );
    }

    public function testRepeatingTheSameStatusDoesNotAddHistory(): void
    {
        $this->seedInsuranceOrderPipeline();
        $order = $this->order();
        $this->record($order, InsuranceStatusEnum::PAID);
        $rows = $order->orderTransitionHistory()->count();

        $this->record($order, InsuranceStatusEnum::PAID);

        $this->assertSame($rows, $order->orderTransitionHistory()->count());
    }

    public function testAnInsuranceOrderThatNeverGotAnInitialStatusDoesNotBreakTheMoneyPath(): void
    {
        $this->seedInsuranceOrderPipeline();
        $order = $this->order(withInitialState: false);

        $this->record($order, InsuranceStatusEnum::PAID);

        $this->assertNull($order->fresh()->order_status_id);
        $this->assertSame(
            InsuranceStatusEnum::PAID->value,
            $order->fresh()->get(InsuranceCustomFieldEnum::STATUS->value)
        );
    }

    public function testALegacyOrderWithoutATypeJustRecordsTheInsuranceStatus(): void
    {
        $this->seedInsuranceOrderPipeline();
        $order = $this->order(typed: false);

        $this->record($order, InsuranceStatusEnum::QUOTED);
        $this->record($order, InsuranceStatusEnum::PAID);
        $this->record($order, InsuranceStatusEnum::EMITTED);

        $this->assertNull($order->fresh()->order_types_id);
        $this->assertNull($order->fresh()->order_status_id);
        $this->assertSame(0, $order->orderTransitionHistory()->count());
        $this->assertSame(
            InsuranceStatusEnum::EMITTED->value,
            $order->fresh()->get(InsuranceCustomFieldEnum::STATUS->value)
        );
    }

    public function testAnOrderOfAnotherTypeIsNotMirrored(): void
    {
        $this->seedInsuranceOrderPipeline();
        $order = $this->order(typed: false);
        $order->setOrderType('movipass');

        $this->record($order, InsuranceStatusEnum::PAID);

        $this->assertNull($order->fresh()->order_status_id);
    }

    public function testCardVelocityLimitsApplyToInsuranceOrdersOnceConfigured(): void
    {
        $orderType = $this->seedInsuranceOrderPipeline();
        $orderType->config = ['card_velocity' => ['max_cards_daily' => 2]];
        $orderType->saveOrFail();

        $this->pay($this->order(), '1111');
        $second = $this->pay($this->order(), '2222');

        $this->expectException(ValidationException::class);

        new EnforceCardVelocityLimitAction($second)->execute();
    }

    public function testCardVelocityIsOffForInsuranceUntilConfigured(): void
    {
        $this->seedInsuranceOrderPipeline();

        $this->pay($this->order(), '1111');
        $this->pay($this->order(), '2222');
        $third = $this->pay($this->order(), '3333');

        new EnforceCardVelocityLimitAction($third)->execute();

        $this->assertSame(PaymentStatusEnum::PENDING->value, $third->refresh()->status);
    }

    private function pay(Order $order, string $lastFour): Payments
    {
        $payment = new Payments();
        $payment->apps_id = $this->kanvasApp->getId();
        $payment->companies_id = $order->companies_id;
        $payment->users_id = $this->kanvasUser->getId();
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
        $payment->status = PaymentStatusEnum::PENDING->value;
        $payment->is_deleted = false;
        $payment->saveOrFail();

        return $payment;
    }
}
