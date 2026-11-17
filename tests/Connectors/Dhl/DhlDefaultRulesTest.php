<?php

declare(strict_types=1);

namespace Tests\Connectors\Dhl;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Dhl\Activities\CreateDhlShipmentActivity;
use Kanvas\Connectors\Dhl\Activities\FlagCancelledDhlShipmentActivity;
use Kanvas\Connectors\Dhl\Handlers\DhlHandler;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Actions\EnsureDeliveryOrderNoticeRuleAction;
use Kanvas\Souk\Orders\Activities\NotifyDeliveryOrderStatusActivity;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Rules\Actions\EnsureIntegrationDefaultRulesAction;
use Kanvas\Workflow\Rules\Actions\RemoveIntegrationDefaultRulesAction;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleType;
use Tests\Connectors\Traits\CreatesDhlOrder;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

final class DhlDefaultRulesTest extends TestCase
{
    use CreatesDhlOrder;
    use DatabaseTransactions;
    use InventoryCases;

    protected $connectionsToTransact = [null, 'workflow', 'inventory'];

    private Companies $company;
    private IntegrationsCompany $integrationCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Companies::factory()->create();
        $region = $this->createDefaultRegion($this->company, app(Apps::class), auth()->user());
        RuleType::firstOrCreate(['name' => WorkflowEnum::STATUS_TRANSITION->value]);
        Action::factory()->create(['name' => 'DHL Create Shipment', 'model_name' => CreateDhlShipmentActivity::class]);
        Action::factory()->create(['name' => 'Delivery Order Status Notice', 'model_name' => NotifyDeliveryOrderStatusActivity::class]);
        Action::factory()->create(['name' => 'DHL Flag Cancelled Shipment', 'model_name' => FlagCancelledDhlShipmentActivity::class]);

        $this->integrationCompany = $this->connectDhlIntegration($this->company, $region);

        new EnsureIntegrationDefaultRulesAction($this->integrationCompany, app(Apps::class), auth()->user())->execute();
        new EnsureDeliveryOrderNoticeRuleAction(app(Apps::class), $this->company)->execute();
    }

    public function testBookingRuleFiresOnlyForReadyForDispatch(): void
    {
        $rule = $this->rule(DhlHandler::BOOKING_RULE_NAME);

        $this->assertTrue($rule->matches(new Order(), ['to_status' => 'ready-for-dispatch']));
        $this->assertFalse($rule->matches(new Order(), ['to_status' => 'paid']));
        $this->assertFalse($rule->matches(new Order(), ['to_status' => 'dispatched']));
    }

    public function testStatusNoticeRuleFiresForEveryNotifiedStatus(): void
    {
        $rule = $this->rule(NotifyDeliveryOrderStatusActivity::RULE_NAME);

        foreach (['verifying', 'paid', 'ready-for-dispatch', 'dispatched', 'delivered', 'returned', 'out-of-stock', 'cancelled'] as $status) {
            $this->assertTrue($rule->matches(new Order(), ['to_status' => $status]), $status);
        }

        $this->assertFalse($rule->matches(new Order(), ['to_status' => 'something-else']));
    }

    public function testCancelledReviewRuleFiresOnlyForCancelled(): void
    {
        $rule = $this->rule(DhlHandler::CANCELLED_REVIEW_RULE_NAME);

        $this->assertTrue($rule->matches(new Order(), ['to_status' => 'cancelled']));
        $this->assertFalse($rule->matches(new Order(), ['to_status' => 'returned']));
    }

    public function testTheSetupCommandAfterConnectingDhlReusesTheSameStatusNoticeRule(): void
    {
        $this->artisan('kanvas:souk-setup-delivery-order-type', ['--company' => $this->company->getId()])->assertSuccessful();

        $this->assertSame(1, $this->noticeRules()->count());
    }

    public function testDhlDeclaresOnlyItsOwnDefaultRules(): void
    {
        $names = array_map(fn ($rule): string => $rule->name, new DhlHandler(app(Apps::class), $this->company, new Regions(), [])->defaultRules());

        $this->assertSame([DhlHandler::BOOKING_RULE_NAME, DhlHandler::CANCELLED_REVIEW_RULE_NAME], $names);
    }

    public function testDisconnectingDhlRemovesItsRulesButKeepsTheStatusNotice(): void
    {
        new RemoveIntegrationDefaultRulesAction($this->integrationCompany, app(Apps::class))->execute();

        $this->assertSame(1, $this->noticeRules()->where('is_deleted', 0)->count());
        $this->assertSame(0, $this->namedRules(DhlHandler::BOOKING_RULE_NAME)->where('is_deleted', 0)->count());
        $this->assertSame(0, $this->namedRules(DhlHandler::CANCELLED_REVIEW_RULE_NAME)->where('is_deleted', 0)->count());
    }

    public function testEachRuleRunsItsActivity(): void
    {
        $this->assertSame(
            CreateDhlShipmentActivity::class,
            $this->rule(DhlHandler::BOOKING_RULE_NAME)->workflowActivities->first()->activity->actionClass()
        );
        $this->assertSame(
            NotifyDeliveryOrderStatusActivity::class,
            $this->rule(NotifyDeliveryOrderStatusActivity::RULE_NAME)->workflowActivities->first()->activity->actionClass()
        );
        $this->assertSame(
            FlagCancelledDhlShipmentActivity::class,
            $this->rule(DhlHandler::CANCELLED_REVIEW_RULE_NAME)->workflowActivities->first()->activity->actionClass()
        );
    }

    private function noticeRules(): Builder
    {
        return $this->namedRules(NotifyDeliveryOrderStatusActivity::RULE_NAME);
    }

    private function namedRules(string $name): Builder
    {
        return Rule::query()
            ->where('name', $name)
            ->where('companies_id', $this->company->getId())
            ->where('apps_id', app(Apps::class)->getId());
    }

    private function rule(string $name): Rule
    {
        return $this->namedRules($name)->firstOrFail();
    }
}
