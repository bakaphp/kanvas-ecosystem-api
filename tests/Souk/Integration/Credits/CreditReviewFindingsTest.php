<?php

declare(strict_types=1);

namespace Tests\Souk\Integration\Credits;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Souk\Discounts\Actions\ApplyCreditToOrderAction;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Discounts\Services\DiscountService;
use Kanvas\Souk\Payments\Actions\CreatePaymentAction;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Tests\TestCase;

final class CreditReviewFindingsTest extends TestCase
{
    use BuildsCreditScenarios;
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'commerce', 'crm'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDiscountTypes();
    }

    public function testCancellingStillRestoresWhenTheSpentCreditWasSoftDeleted(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $credit = $this->issueCredit($company, 40);
        $order = $this->orderFor($company, 100);
        new ApplyCreditToOrderAction($order, $credit)->execute();
        $credit->refresh()->forceFill(['is_deleted' => true])->saveQuietly();

        $order->refresh()->cancel();

        $this->assertSame(100.0, $order->refresh()->total_net_amount);
        $restored = Discount::where('companies_id', $company->getId())->latest('id')->firstOrFail();
        $this->assertSame(40.0, $restored->value);
        $this->assertTrue($restored->canBeUsed());
    }

    public function testARemainderKeepsTheValidityWindowOfItsSource(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $credit = $this->issueCredit($company, 150);
        $credit->forceFill(['start_date' => now()->subDay(), 'end_date' => now()->addDays(10)])->saveQuietly();

        new ApplyCreditToOrderAction($this->orderFor($company, 100), $credit->refresh())->execute();

        $remainder = Discount::where('companies_id', $company->getId())->latest('id')->firstOrFail();
        $this->assertSame(50.0, $remainder->value);
        $this->assertSame($credit->end_date->toDateTimeString(), $remainder->end_date->toDateTimeString());
    }

    public function testACreditThatCannotApplyDoesNotBlockTheNextOne(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $order = $this->orderFor($company, 100);
        $blocked = $this->issueCredit($company, 10, 'already on this order');
        $blocked->forceFill(['usage_limit' => 2, 'created_at' => now()->subDay()])->saveQuietly();
        new ApplyCreditToOrderAction($order, $blocked->refresh())->execute();
        $next = $this->issueCredit($company, 20, 'next');

        $applied = new DiscountService($order->app, $order->company)->applyFirstAvailableCredit($order->refresh());

        $this->assertSame($next->getId(), $applied->getId());
        $this->assertSame(70.0, $order->refresh()->total_net_amount);
    }

    public function testNonPositiveCreditsAreNeverPickedUp(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $this->issueCredit($company, 0);
        $order = $this->orderFor($company, 100);

        $this->assertNull(new DiscountService($order->app, $order->company)->applyFirstAvailableCredit($order));
    }

    public function testTheDueTotalAfterACreditIsWhatAPaymentShouldCharge(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $order = $this->orderFor($company, 100);
        new ApplyCreditToOrderAction($order, $this->issueCredit($company, 40))->execute();
        $order->refresh();

        $this->assertSame(60.0, $order->getTotalDueAmount());
        $this->assertSame(100.0, $order->getTotalAmount());

        $action = new CreatePaymentAction($order, auth()->user());
        $action->runWorkflow = false;
        $payment = $action->execute([
            'payment_method_type' => 'cash',
            'status' => PaymentStatusEnum::PAID->value,
            'amount' => $order->getTotalDueAmount(),
        ]);

        $this->assertSame(60.0, (float) $payment->amount);
        $this->assertTrue($order->refresh()->isPaid());
    }
}
