<?php

declare(strict_types=1);

namespace Tests\Souk\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Actions\EnforceCardVelocityLimitAction;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\PaymentLogs;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class EnforceCardVelocityLimitActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'crm'];

    private Apps $kanvasApp;
    private Companies $company;
    private Users $customer;
    private People $people;
    private OrderTypes $limitedType;
    private OrderTypes $unlimitedType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = Companies::factory()->create(['users_id' => auth()->user()->getId()]);
        $this->customer = $this->createUser();
        $this->people = People::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId($this->customer->getId())
            ->create();
        $this->limitedType = $this->orderType('paso_rapido', ['max_cards_daily' => 4, 'ban_cards_daily' => 6]);
        $this->unlimitedType = $this->orderType('movipass');
    }

    protected function tearDown(): void
    {
        $this->company->deleteAllSettings();

        parent::tearDown();
    }

    public function testThirdDistinctCardIsAllowed(): void
    {
        $this->pay('1111');
        $this->pay('2222');

        $this->assertAllowed($this->pay('3333'));
    }

    public function testFourthNewCardIsRejectedWithTheGenericMessage(): void
    {
        $this->pay('1111');
        $this->pay('2222');
        $this->pay('3333');
        $payment = $this->pay('4444');

        $this->assertRejected($payment);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->refresh()->status);
        $this->assertTrue(
            PaymentLogs::where('payments_id', $payment->getId())->where('event_type', 'payment_card_velocity_blocked')->exists()
        );
        $this->assertFalse($this->customer->getAppProfile($this->kanvasApp)->isBanned());
    }

    public function testACardAlreadyUsedInTheWindowIsAllowed(): void
    {
        $this->payWith(['1111', '2222', '3333', '4444', '5555']);

        $this->assertAllowed($this->pay('2222'));
    }

    public function testSixthCardBansTheCustomer(): void
    {
        $this->payWith(['1111', '2222', '3333', '4444', '5555']);

        $this->assertRejected($this->pay('6666'));
        $this->assertTrue($this->customer->getAppProfile($this->kanvasApp)->isBanned());
    }

    public function testCardsOutsideTheWindowDoNotCount(): void
    {
        $this->payWith(['1111', '2222', '3333'], createdAt: now()->subHours(25));

        $this->assertAllowed($this->pay('4444'));
    }

    public function testOrderTypeWithoutConfigIsNotLimited(): void
    {
        $this->payWith(['1111', '2222', '3333', '4444', '5555'], $this->unlimitedType);

        $this->assertAllowed($this->pay('6666', $this->unlimitedType));
        $this->assertFalse($this->customer->getAppProfile($this->kanvasApp)->isBanned());
    }

    public function testCardsOnOtherOrderTypesDoNotCount(): void
    {
        $this->payWith(['1111', '2222', '3333'], $this->unlimitedType);

        $this->assertAllowed($this->pay('4444'));
    }

    public function testCorporateCompaniesUseTheCorporateLimitWhichDefaultsToNone(): void
    {
        $this->company->set('is_corporate', '1');
        $this->payWith(['1111', '2222', '3333', '4444', '5555']);

        $this->assertAllowed($this->pay('6666'));
    }

    public function testCorporateLimitComesFromTheOrderTypeConfig(): void
    {
        $this->company->set('is_corporate', '1');
        $this->limitedType->config = ['card_velocity' => ['corporate_max_cards_daily' => 2]];
        $this->limitedType->saveOrFail();

        $this->pay('1111');

        $this->assertRejected($this->pay('2222'));
    }

    private function assertAllowed(Payments $payment): void
    {
        new EnforceCardVelocityLimitAction($payment)->execute();

        $this->assertSame(PaymentStatusEnum::PENDING->value, $payment->refresh()->status);
    }

    private function assertRejected(Payments $payment): void
    {
        try {
            new EnforceCardVelocityLimitAction($payment)->execute();
            $this->fail('The card velocity limit let the payment through.');
        } catch (ValidationException $e) {
            $this->assertSame(
                'No pudimos procesar el pago en este momento. Si el problema persiste, contacta a soporte.',
                $e->getMessage()
            );
        }
    }

    private function payWith(array $lastFours, ?OrderTypes $type = null, ?Carbon $createdAt = null): void
    {
        foreach ($lastFours as $lastFour) {
            $this->pay($lastFour, $type, $createdAt);
        }
    }

    private function pay(string $lastFour, ?OrderTypes $type = null, ?Carbon $createdAt = null): Payments
    {
        $order = Order::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId($this->customer->getId())
            ->withPeopleId($this->people->getId())
            ->create(['order_types_id' => ($type ?? $this->limitedType)->getId()]);

        $payment = new Payments();
        $payment->apps_id = $this->kanvasApp->getId();
        $payment->companies_id = $this->company->getId();
        $payment->users_id = $this->customer->getId();
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

        if ($createdAt) {
            $payment->created_at = $createdAt;
        }

        $payment->saveOrFail();

        return $payment;
    }

    private function orderType(string $name, ?array $cardVelocity = null): OrderTypes
    {
        $type = new OrderTypes();
        $type->apps_id = $this->kanvasApp->getId();
        $type->companies_id = $this->company->getId();
        $type->name = $name;
        $type->config = $cardVelocity ? ['card_velocity' => $cardVelocity] : null;
        $type->saveOrFail();

        return $type;
    }
}
