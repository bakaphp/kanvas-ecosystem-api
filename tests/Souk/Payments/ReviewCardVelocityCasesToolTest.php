<?php

declare(strict_types=1);

namespace Tests\Souk\Payments;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Neuron\Tools\Souk\ReviewCardVelocityCasesTool;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class ReviewCardVelocityCasesToolTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'crm'];

    public function testNonAdminIsDenied(): void
    {
        $app = app(Apps::class);
        $company = $this->company();

        $result = new ReviewCardVelocityCasesTool()
            ->withContext($app, $company, auth()->user())
            ->forRequestingUser(Users::factory()->create())
            ->__invoke();

        $this->assertFalse($result['success']);
        $this->assertSame('denied', $result['outcome']);
    }

    public function testAllowedWithNoRequestingHumanReturnsBlockedCases(): void
    {
        $app = app(Apps::class);
        $company = $this->company();
        $user = $this->seedBlock($app, $company);

        $result = new ReviewCardVelocityCasesTool()
            ->withContext($app, $company, auth()->user())
            ->__invoke(since: now()->subDay()->toDateString(), until: now()->addDay()->toDateString());

        $this->assertTrue($result['success']);
        $this->assertSame('ok', $result['outcome']);
        $this->assertCount(1, $result['blocked']);
        $this->assertSame($user->getId(), $result['blocked'][0]['user']['id']);
        $this->assertFalse($result['blocked'][0]['user']['still_banned']);
        $this->assertFalse($result['blocked'][0]['is_corporate']);
    }

    public function testEmptyRangeReturnsNoop(): void
    {
        $app = app(Apps::class);
        $company = $this->company();

        $result = new ReviewCardVelocityCasesTool()
            ->withContext($app, $company, auth()->user())
            ->__invoke(since: '2000-01-01', until: '2000-01-01');

        $this->assertTrue($result['success']);
        $this->assertSame('noop', $result['outcome']);
        $this->assertSame([], $result['blocked']);
        $this->assertSame([], $result['at_risk']);
    }

    public function testSecondIdenticalCallInTheSameTurnReturnsTheGuardedRepeat(): void
    {
        $app = app(Apps::class);
        $company = $this->company();
        $this->seedBlock($app, $company);

        $registered = new ReviewCardVelocityCasesTool()->withContext($app, $company, auth()->user());
        $since = now()->subDay()->toDateString();
        $until = now()->addDay()->toDateString();

        $first = (clone $registered)->__invoke(since: $since, until: $until);
        $second = (clone $registered)->__invoke(since: $since, until: $until);

        $this->assertArrayNotHasKey('repeat_call', $first);
        $this->assertTrue($second['repeat_call']);
        $this->assertSame($first['blocked'], $second['blocked']);
    }

    public function testUntilBeforeSinceIsInvalidArgs(): void
    {
        $app = app(Apps::class);
        $company = $this->company();

        $result = new ReviewCardVelocityCasesTool()
            ->withContext($app, $company, auth()->user())
            ->__invoke(since: now()->toDateString(), until: now()->subDay()->toDateString());

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_args', $result['outcome']);
    }

    public function testBadDateStringIsInvalidArgs(): void
    {
        $app = app(Apps::class);
        $company = $this->company();

        $result = new ReviewCardVelocityCasesTool()
            ->withContext($app, $company, auth()->user())
            ->__invoke(since: 'not-a-date');

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_args', $result['outcome']);
    }

    public function testOverflowingDateIsInvalidArgs(): void
    {
        $app = app(Apps::class);
        $company = $this->company();

        $result = new ReviewCardVelocityCasesTool()
            ->withContext($app, $company, auth()->user())
            ->__invoke(since: '2026-02-30');

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_args', $result['outcome']);
    }

    public function testUnknownOrderTypeNameIsInvalidArgs(): void
    {
        $app = app(Apps::class);
        $company = $this->company();

        $result = new ReviewCardVelocityCasesTool()
            ->withContext($app, $company, auth()->user())
            ->__invoke(order_types: 'totally_unknown_type');

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_args', $result['outcome']);
        $this->assertStringContainsString('totally_unknown_type', $result['error']);
    }

    public function testSpanOverMaxRangeIsInvalidArgs(): void
    {
        $app = app(Apps::class);
        $company = $this->company();

        $result = new ReviewCardVelocityCasesTool()
            ->withContext($app, $company, auth()->user())
            ->__invoke(since: now()->subDays(40)->toDateString(), until: now()->toDateString());

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_args', $result['outcome']);
    }

    private function company(): Companies
    {
        return Companies::factory()->create(['users_id' => auth()->user()->getId()]);
    }

    private function seedBlock(Apps $app, Companies $company): Users
    {
        $user = $this->createUser();

        $orderType = new OrderTypes();
        $orderType->apps_id = $app->getId();
        $orderType->companies_id = $company->getId();
        $orderType->name = 'paso_rapido';
        $orderType->saveOrFail();

        $order = Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create(['order_types_id' => $orderType->getId()]);

        $payment = new Payments();
        $payment->apps_id = $app->getId();
        $payment->companies_id = $company->getId();
        $payment->users_id = $user->getId();
        $payment->payment_methods_id = 1;
        $payment->payable_id = $order->getId();
        $payment->payable_type = Order::class;
        $payment->payment_date = now()->toDateString();
        $payment->payment_method = 'card';
        $payment->payment_method_brand = 'visa';
        $payment->payment_method_last_four = '1111';
        $payment->concept = 'Payment ' . $order->reference;
        $payment->amount = 100.0;
        $payment->currency = 'DOP';
        $payment->status = PaymentStatusEnum::FAILED->value;
        $payment->is_deleted = false;
        $payment->saveOrFail();

        $payment->addLog(Payments::CARD_VELOCITY_BLOCKED_EVENT, [
            'order_type' => $orderType->name,
            'cards' => 1,
            'banned' => false,
        ]);

        return $user;
    }
}
