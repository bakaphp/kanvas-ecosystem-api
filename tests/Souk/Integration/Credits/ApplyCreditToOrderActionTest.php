<?php

declare(strict_types=1);

namespace Tests\Souk\Integration\Credits;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Discounts\Actions\ApplyCreditToOrderAction;
use Kanvas\Souk\Discounts\Actions\ApplyDiscountToOrderAction;
use Kanvas\Souk\Discounts\Enums\CustomFieldEnum;
use Kanvas\Souk\Discounts\Enums\DiscountTypeEnum;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Discounts\Models\DiscountType;
use Tests\TestCase;

final class ApplyCreditToOrderActionTest extends TestCase
{
    use BuildsCreditScenarios;
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'commerce', 'crm'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDiscountTypes();
    }

    public function testCreditLargerThanTheOrderBurnsTheRowAndSpawnsTheRemainder(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $credit = $this->issueCredit($company, 150);
        $order = $this->orderFor($company, 100);

        $orderDiscount = new ApplyCreditToOrderAction($order, $credit)->execute();
        $order->refresh();
        $credit->refresh();

        $this->assertSame(100.0, $orderDiscount->amount);
        $this->assertSame(0.0, $order->total_net_amount);
        $this->assertSame(100.0, $order->discount_amount);
        $this->assertSame($credit->getId(), (int) $order->voucher_id);
        $this->assertFalse($credit->canBeUsed());

        $remainder = Discount::where('companies_id', $company->getId())
            ->where('id', '!=', $credit->getId())
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(50.0, $remainder->value);
        $this->assertTrue($remainder->isAutoAppliedCredit());
        $this->assertTrue($remainder->canBeUsed());
        $this->assertSame($credit->getId(), (int) $remainder->get(CustomFieldEnum::PARENT_DISCOUNT_ID->value));
        $this->assertSame($order->getId(), (int) $remainder->get(CustomFieldEnum::SOURCE_ORDER_ID->value));
    }

    public function testCreditSmallerThanTheOrderSpawnsNothing(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $credit = $this->issueCredit($company, 40);
        $order = $this->orderFor($company, 100);

        new ApplyCreditToOrderAction($order, $credit)->execute();
        $order->refresh();

        $this->assertSame(60.0, $order->total_net_amount);
        $this->assertSame(1, Discount::where('companies_id', $company->getId())->count());
    }

    public function testCreditStacksOnACodeDiscountWithoutTakingItsNameOrDrivingTheNetNegative(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $order = $this->orderFor($company, 100);

        $promo = Discount::factory()
            ->withAppId($order->apps_id)
            ->withCompanyId($company->getId())
            ->active()
            ->create([
                'name' => 'PROMO 30',
                'discount_type_id' => DiscountType::getByName(DiscountTypeEnum::FIXED_AMOUNT->label())->getId(),
                'value' => 30,
                'is_percentage' => false,
                'code' => 'PROMO30',
            ]);
        new ApplyDiscountToOrderAction($order, $promo)->execute();

        $credit = $this->issueCredit($company, 500, 'Big credit');
        new ApplyCreditToOrderAction($order->refresh(), $credit)->execute();
        $order->refresh();

        $this->assertSame(0.0, $order->total_net_amount);
        $this->assertSame(100.0, $order->discount_amount);
        $this->assertSame('PROMO 30', $order->discount_name);
        $this->assertSame($promo->getId(), (int) $order->voucher_id);

        $remainder = Discount::where('companies_id', $company->getId())->latest('id')->firstOrFail();
        $this->assertSame(430.0, $remainder->value);
    }

    public function testASpentCreditCannotBeAppliedAgain(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $credit = $this->issueCredit($company, 10);
        new ApplyCreditToOrderAction($this->orderFor($company, 100), $credit)->execute();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already been used');

        new ApplyCreditToOrderAction($this->orderFor($company, 100), $credit)->execute();
    }

    public function testAFullyDiscountedOrderCannotConsumeACredit(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $order = $this->orderFor($company, 100);
        new ApplyCreditToOrderAction($order, $this->issueCredit($company, 100))->execute();
        $spare = $this->issueCredit($company, 25);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('nothing left to credit');

        new ApplyCreditToOrderAction($order->refresh(), $spare)->execute();
    }

    public function testACreditFromAnotherCompanyIsRejected(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $credit = $this->issueCredit($company, 10);
        $otherCompanyOrder = $this->orderFor(
            Companies::factory()->create(['users_id' => auth()->user()->getId()]),
            100
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('another company');

        new ApplyCreditToOrderAction($otherCompanyOrder, $credit)->execute();
    }

    public function testAPromoDiscountIsRejectedByTheCreditPath(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $order = $this->orderFor($company, 100);
        $promo = Discount::factory()->withAppId($order->apps_id)->withCompanyId($company->getId())->active()->create([
            'discount_type_id' => DiscountType::getByName(DiscountTypeEnum::FIXED_AMOUNT->label())->getId(),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not an auto applied credit');

        new ApplyCreditToOrderAction($order, $promo)->execute();
    }
}
