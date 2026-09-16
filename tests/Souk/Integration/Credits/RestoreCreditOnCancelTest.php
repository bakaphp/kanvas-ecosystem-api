<?php

declare(strict_types=1);

namespace Tests\Souk\Integration\Credits;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Souk\Discounts\Actions\ApplyCreditToOrderAction;
use Kanvas\Souk\Discounts\Actions\ApplyDiscountToOrderAction;
use Kanvas\Souk\Discounts\Actions\RestoreCreditFromCancelledOrderAction;
use Kanvas\Souk\Discounts\Enums\CustomFieldEnum;
use Kanvas\Souk\Discounts\Enums\DiscountTypeEnum;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Discounts\Models\DiscountType;
use Kanvas\Souk\Discounts\Services\DiscountService;
use Kanvas\Souk\Orders\Actions\UpdateOrderAction;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Tests\TestCase;

final class RestoreCreditOnCancelTest extends TestCase
{
    use BuildsCreditScenarios;
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'commerce', 'crm'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDiscountTypes();
    }

    public function testCancellingReturnsTheConsumedAmountAsANewCreditThatAppliesToTheNextOrder(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $credit = $this->issueCredit($company, 40);
        $order = $this->orderFor($company, 100);
        new ApplyCreditToOrderAction($order, $credit)->execute();

        $order->refresh()->cancel();
        $order->refresh();

        $this->assertSame(100.0, $order->total_net_amount);
        $this->assertSame(0.0, $order->discount_amount);
        $this->assertNull($order->voucher_id);
        $this->assertSame(0, $order->orderDiscounts()->notDeleted()->count());

        $restored = Discount::where('companies_id', $company->getId())->latest('id')->firstOrFail();
        $this->assertSame(40.0, $restored->value);
        $this->assertTrue($restored->canBeUsed());
        $this->assertSame($credit->getId(), (int) $restored->get(CustomFieldEnum::PARENT_DISCOUNT_ID->value));
        $this->assertSame($order->getId(), (int) $restored->get(CustomFieldEnum::SOURCE_ORDER_ID->value));

        $next = $this->orderFor($company, 100);
        $applied = new DiscountService($next->app, $next->company)->applyFirstAvailableCredit($next);
        $this->assertSame($restored->getId(), $applied->getId());
        $this->assertSame(60.0, $next->refresh()->total_net_amount);
    }

    public function testCancellingTwiceRestoresOnce(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $order = $this->orderFor($company, 100);
        new ApplyCreditToOrderAction($order, $this->issueCredit($company, 40))->execute();

        $order->refresh()->cancel();
        $order->refresh()->cancel();
        $order->refresh();
        $order->status = OrderStatusEnum::COMPLETED->value;
        $order->saveOrFail();
        $order->cancel();

        $this->assertSame(2, Discount::where('companies_id', $company->getId())->count());
    }

    public function testCancellingThroughUpdateOrderRestoresToo(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $order = $this->orderFor($company, 100);
        new ApplyCreditToOrderAction($order, $this->issueCredit($company, 40))->execute();

        new UpdateOrderAction($order->refresh(), ['status' => OrderStatusEnum::CANCELED->value], auth()->user())->execute();

        $this->assertSame(100.0, $order->refresh()->total_net_amount);
        $this->assertSame(2, Discount::where('companies_id', $company->getId())->count());
    }

    public function testOnlyCreditsAreRestoredACodeDiscountStaysOnTheCancelledOrder(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $order = $this->orderFor($company, 100);
        $promo = Discount::factory()->withAppId($order->apps_id)->withCompanyId($company->getId())->active()->create([
            'name' => 'PROMO 30',
            'discount_type_id' => DiscountType::getByName(DiscountTypeEnum::FIXED_AMOUNT->label())->getId(),
            'value' => 30,
            'is_percentage' => false,
            'code' => 'PROMO30',
        ]);
        new ApplyDiscountToOrderAction($order, $promo)->execute();
        new ApplyCreditToOrderAction($order->refresh(), $this->issueCredit($company, 40))->execute();

        $order->refresh()->cancel();
        $order->refresh();

        $this->assertSame(70.0, $order->total_net_amount);
        $this->assertSame('PROMO 30', $order->discount_name);
        $this->assertSame($promo->getId(), (int) $order->voucher_id);
    }

    public function testTheOrderVoucherFallsBackToTheSurvivingCodeDiscountWhenTheCreditWasFirst(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $order = $this->orderFor($company, 100);
        new ApplyCreditToOrderAction($order, $this->issueCredit($company, 40, 'Credit'))->execute();
        $promo = Discount::factory()->withAppId($order->apps_id)->withCompanyId($company->getId())->active()->create([
            'name' => 'PROMO 30',
            'discount_type_id' => DiscountType::getByName(DiscountTypeEnum::FIXED_AMOUNT->label())->getId(),
            'value' => 30,
            'is_percentage' => false,
            'code' => 'PROMO30',
        ]);
        // ApplyDiscountToOrderAction overwrites voucher_id unconditionally, so put the credit back as the order's voucher
        new ApplyDiscountToOrderAction($order->refresh(), $promo)->execute();
        $order->refresh();
        $order->voucher_id = $order->orderDiscounts()->orderBy('id')->firstOrFail()->discount_id;
        $order->discount_name = 'Credit';
        $order->saveOrFail();

        $order->refresh()->cancel();
        $order->refresh();

        $this->assertSame(70.0, $order->total_net_amount);
        $this->assertSame($promo->getId(), (int) $order->voucher_id);
        $this->assertSame('PROMO 30', $order->discount_name);
    }

    public function testAnOrderWithoutCreditsCancelsAsANoOp(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $order = $this->orderFor($company, 100);
        $before = Discount::where('companies_id', $company->getId())->count();

        $this->assertSame([], new RestoreCreditFromCancelledOrderAction($order)->execute());
        $order->cancel();

        $this->assertSame(100.0, $order->refresh()->total_net_amount);
        $this->assertSame($before, Discount::where('companies_id', $company->getId())->count());
    }

    public function testAFulfillmentCancelDoesNotRestore(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $order = $this->orderFor($company, 100);
        new ApplyCreditToOrderAction($order, $this->issueCredit($company, 40))->execute();

        $order->refresh()->fulfillCancelled();

        $this->assertSame(60.0, $order->refresh()->total_net_amount);
        $this->assertSame(1, Discount::where('companies_id', $company->getId())->count());
    }
}
