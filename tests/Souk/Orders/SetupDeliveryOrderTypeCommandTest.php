<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Orders\Activities\NotifyDeliveryOrderStatusActivity;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleType;
use Tests\TestCase;

final class SetupDeliveryOrderTypeCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'commerce', 'ecosystem'];

    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Companies::factory()->create();
        RuleType::firstOrCreate(['name' => WorkflowEnum::STATUS_TRANSITION->value]);
        Action::factory()->create(['name' => 'Delivery Order Status Notice', 'model_name' => NotifyDeliveryOrderStatusActivity::class]);
    }

    public function testWithoutACompanyItOnlyCreatesTheOrderType(): void
    {
        $this->artisan('kanvas:souk-setup-delivery-order-type')->assertSuccessful();

        $this->assertTrue(OrderTypes::query()->where('apps_id', app(Apps::class)->getId())->where('name', 'delivery')->exists());
        $this->assertSame(0, $this->noticeRules()->count());
    }

    public function testTheCompanyOptionCreatesTheStatusNoticeRuleOnce(): void
    {
        $this->artisan('kanvas:souk-setup-delivery-order-type', ['--company' => $this->company->getId()])->assertSuccessful();
        $this->artisan('kanvas:souk-setup-delivery-order-type', ['--company' => $this->company->getId()])->assertSuccessful();

        $rules = $this->noticeRules()->get();
        $this->assertCount(1, $rules);
        $rule = $rules->first();
        $this->assertSame(
            NotifyDeliveryOrderStatusActivity::class,
            $rule->workflowActivities->first()->activity->actionClass()
        );

        foreach (['verifying', 'confirmed', 'ready-for-dispatch', 'dispatched', 'delivered', 'returned', 'out-of-stock', 'cancelled'] as $status) {
            $this->assertTrue($rule->matches(new Order(), ['to_status' => $status]), $status);
        }

        $this->assertFalse($rule->matches(new Order(), ['to_status' => 'something-else']));
    }

    private function noticeRules(): Builder
    {
        return Rule::query()
            ->where('name', NotifyDeliveryOrderStatusActivity::RULE_NAME)
            ->where('companies_id', $this->company->getId())
            ->where('apps_id', app(Apps::class)->getId());
    }
}
