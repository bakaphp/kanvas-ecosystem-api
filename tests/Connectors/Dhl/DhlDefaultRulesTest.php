<?php

declare(strict_types=1);

namespace Tests\Connectors\Dhl;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Dhl\Activities\CreateDhlShipmentActivity;
use Kanvas\Connectors\Dhl\Activities\NotifyCabinetOrderStatusActivity;
use Kanvas\Connectors\Dhl\Handlers\DhlHandler;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Actions\EnsureIntegrationDefaultRulesAction;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleType;
use Tests\Connectors\Traits\CreatesDhlCabinetOrder;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

final class DhlDefaultRulesTest extends TestCase
{
    use CreatesDhlCabinetOrder;
    use DatabaseTransactions;
    use InventoryCases;

    protected $connectionsToTransact = [null, 'workflow', 'inventory'];

    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Companies::factory()->create();
        $region = $this->createDefaultRegion($this->company, app(Apps::class), auth()->user());
        RuleType::firstOrCreate(['name' => WorkflowEnum::STATUS_TRANSITION->value]);
        Action::factory()->create(['name' => 'DHL Create Shipment', 'model_name' => CreateDhlShipmentActivity::class]);
        Action::factory()->create(['name' => 'DHL Cabinet Order Status Notice', 'model_name' => NotifyCabinetOrderStatusActivity::class]);

        new EnsureIntegrationDefaultRulesAction(
            $this->connectDhlIntegration($this->company, $region),
            app(Apps::class),
            auth()->user()
        )->execute();
    }

    public function testBookingRuleFiresOnlyForReadyForDispatch(): void
    {
        $rule = $this->rule(DhlHandler::BOOKING_RULE_NAME);

        $this->assertTrue($rule->matches(new Order(), ['to_status' => 'ready-for-dispatch']));
        $this->assertFalse($rule->matches(new Order(), ['to_status' => 'paid']));
        $this->assertFalse($rule->matches(new Order(), ['to_status' => 'dispatched']));
    }

    public function testStatusNoticeRuleFiresForTheCustomerFacingExits(): void
    {
        $rule = $this->rule(DhlHandler::STATUS_NOTICE_RULE_NAME);

        foreach (['delivered', 'returned', 'out-of-stock', 'cancelled'] as $status) {
            $this->assertTrue($rule->matches(new Order(), ['to_status' => $status]), $status);
        }

        $this->assertFalse($rule->matches(new Order(), ['to_status' => 'dispatched']));
        $this->assertFalse($rule->matches(new Order(), ['to_status' => 'ready-for-dispatch']));
    }

    public function testEachRuleRunsItsActivity(): void
    {
        $this->assertSame(
            CreateDhlShipmentActivity::class,
            $this->rule(DhlHandler::BOOKING_RULE_NAME)->workflowActivities->first()->activity->actionClass()
        );
        $this->assertSame(
            NotifyCabinetOrderStatusActivity::class,
            $this->rule(DhlHandler::STATUS_NOTICE_RULE_NAME)->workflowActivities->first()->activity->actionClass()
        );
    }

    private function rule(string $name): Rule
    {
        return Rule::query()
            ->where('name', $name)
            ->where('companies_id', $this->company->getId())
            ->where('apps_id', app(Apps::class)->getId())
            ->firstOrFail();
    }
}
