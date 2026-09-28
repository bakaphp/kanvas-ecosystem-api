<?php

declare(strict_types=1);

namespace Tests\Souk\Integration\Credits;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Discounts\Actions\ApplyCreditToOrderAction;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Discounts\Services\DiscountService;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Models\Order;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

final class AutoApplyCreditOnOrderTest extends TestCase
{
    use BuildsCreditScenarios;
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'commerce', 'crm'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDiscountTypes();
    }

    public function testTheOldestUnspentCreditOfTheCompanyIsApplied(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $older = $this->issueCredit($company, 30, 'older');
        $older->forceFill(['created_at' => now()->subDay()])->saveQuietly();
        $newer = $this->issueCredit($company, 30, 'newer');
        $order = $this->orderFor($company, 100);

        $applied = $this->service($order)->applyFirstAvailableCredit($order);
        $order->refresh();

        $this->assertSame($older->getId(), $applied->getId());
        $this->assertSame(70.0, $order->total_net_amount);
        $this->assertTrue($newer->refresh()->canBeUsed());
    }

    public function testACompanyWithoutCreditsIsLeftUntouched(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $order = $this->orderFor($company, 100);

        $this->assertNull($this->service($order)->applyFirstAvailableCredit($order));
        $this->assertSame(100.0, $order->refresh()->total_net_amount);
        $this->assertSame(0, $order->orderDiscounts()->count());
    }

    public function testAnotherCompanysCreditIsNeverPickedUp(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $other = Companies::factory()->create(['users_id' => auth()->user()->getId()]);
        $this->issueCredit($other, 30);
        $order = $this->orderFor($company, 100);

        $this->assertNull($this->service($order)->applyFirstAvailableCredit($order));
    }

    public function testAnExhaustedCreditIsSkippedAndItsRemainderIsUsedNext(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $credit = $this->issueCredit($company, 150);
        $first = $this->orderFor($company, 100);
        new ApplyCreditToOrderAction($first, $credit)->execute();

        $second = $this->orderFor($company, 100);
        $applied = $this->service($second)->applyFirstAvailableCredit($second);
        $second->refresh();

        $this->assertNotSame($credit->getId(), $applied->getId());
        $this->assertSame(50.0, $applied->value);
        $this->assertSame(50.0, $second->total_net_amount);
        $this->assertSame(0, $this->service($second)->getApplicableCredits()->count());
    }

    public function testInactiveAndCodedDiscountsAreNotCredits(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $inactive = $this->issueCredit($company, 30)->forceFill(['is_active' => false]);
        $inactive->saveQuietly();
        $coded = $this->issueCredit($company, 30)->forceFill(['code' => 'NOT-A-CREDIT']);
        $coded->saveQuietly();
        $order = $this->orderFor($company, 100);

        $this->assertSame(0, $this->service($order)->getApplicableCredits()->count());
        $this->assertSame(2, Discount::whereIn('id', [$inactive->getId(), $coded->getId()])->count());
    }

    public function testAnOrderAlreadyFullyDiscountedIsSkippedWithoutError(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $order = $this->orderFor($company, 100);
        new ApplyCreditToOrderAction($order, $this->issueCredit($company, 100))->execute();
        $spare = $this->issueCredit($company, 25);

        $this->assertNull($this->service($order)->applyFirstAvailableCredit($order->refresh()));
        $this->assertTrue($spare->refresh()->canBeUsed());
    }

    #[Group('serial')]
    public function testUnderB2BCompanyGroupTheCreditIsMatchedToTheBuyerNotTheGlobalCompany(): void
    {
        $app = app(Apps::class);
        $buyer = auth()->user()->getCurrentCompany();
        $globalB2B = Companies::factory()->create(['users_id' => auth()->user()->getId()]);
        $credit = $this->issueCredit($buyer, 30);
        $order = $this->orderFor($globalB2B, 100);

        $this->assertSame($globalB2B->getId(), $order->buyerCompany()->getId());

        $app->set(ConfigurationEnum::USE_B2B_COMPANY_GROUP->value, 1);
        $app->set(ConfigurationEnum::B2B_GLOBAL_COMPANY->value, $globalB2B->getId());

        try {
            $this->assertSame($buyer->getId(), $order->buyerCompany()->getId());

            $applied = new DiscountService($order->app, $order->buyerCompany())->applyFirstAvailableCredit($order);

            $this->assertSame($credit->getId(), $applied->getId());
            $this->assertSame(70.0, $order->refresh()->total_net_amount);
        } finally {
            $app->del(ConfigurationEnum::USE_B2B_COMPANY_GROUP->value);
            $app->del(ConfigurationEnum::B2B_GLOBAL_COMPANY->value);
        }
    }

    private function service(Order $order): DiscountService
    {
        return new DiscountService($order->app, $order->company);
    }
}
