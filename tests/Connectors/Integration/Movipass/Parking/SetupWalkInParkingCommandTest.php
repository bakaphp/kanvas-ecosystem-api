<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass\Parking;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Parking\Actions\SetupWalkInParkingAction;
use Kanvas\Connectors\Movipass\Workflows\Activities\ParkingExitDecisionActivity;
use Kanvas\Connectors\Movipass\Workflows\Activities\ParkingPassScannedActivity;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Passes\DataTransferObject\WalkInAccess;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use PHPUnit\Framework\Attributes\Group;
use Tests\Connectors\Support\CataloguesParkingActivities;
use Tests\Event\Concerns\BuildsWalkInParking;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

final class SetupWalkInParkingCommandTest extends TestCase
{
    use BuildsWalkInParking;
    use CataloguesParkingActivities;
    use DatabaseTransactions;
    use InventoryCases;

    protected $apps;
    protected $user;
    protected $company;
    protected $region;

    protected function connectionsToTransact(): array
    {
        return [null, 'event', 'commerce', 'workflow'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Movipass integration tests are skipped in CI');
        }

        $this->apps = app(Apps::class);
        $this->user = Auth::user();
        $this->company = $this->user->getCurrentCompany();
        $this->region = Regions::getDefault($this->company, $this->apps);
    }

    public function testItWritesTheAttributeRateOrderTypeAndTheTwoRules(): void
    {
        $orderType = $this->preparedProduct();

        $this->assertSame(0, $this->runSetup());

        $access = WalkInAccess::forResource($this->gatedProduct->refresh());
        $this->assertSame(2, $access->maxUses);
        $this->assertSame(2, $access->decisionFromUse);
        $this->assertSame(30, $access->expireUnusedMinutes);
        $this->assertSame(0.75, $this->gatedVariant->variantWarehouses()->first()->refresh()->config['rates']['hourly']);
        $this->assertContains(
            $this->gatedProduct->productsType->slug,
            $orderType->refresh()->config[OrderTypes::EVENT_BOOKING]['product_types']
        );

        $exitRule = $this->ruleNamed(SetupWalkInParkingAction::EXIT_DECISION_RULE_NAME)->sole();
        $scannedRule = $this->ruleNamed(SetupWalkInParkingAction::PASS_SCANNED_RULE_NAME)->sole();
        $this->assertFalse((bool) $exitRule->is_async);
        $this->assertTrue((bool) $scannedRule->is_async);
        $this->assertSame(WorkflowEnum::PASS_SCAN_REQUESTED->value, $exitRule->type->name);
        $this->assertSame(WorkflowEnum::PASS_SCANNED->value, $scannedRule->type->name);
        $this->assertSame(
            SystemModulesRepository::getByModelName(EventVersion::class, $this->apps)->getId(),
            (int) $exitRule->systems_modules_id
        );
        $this->assertSame(
            ParkingExitDecisionActivity::class,
            $exitRule->workflowActivities()->sole()->activity->action->model_name
        );
        $this->assertSame(
            ParkingPassScannedActivity::class,
            $scannedRule->workflowActivities()->sole()->activity->action->model_name
        );
    }

    public function testRunningTwiceKeepsOneRuleOfEachAndMergesInsteadOfClobbering(): void
    {
        $orderType = $this->preparedProduct();
        $this->gatedProduct->addAttribute(WalkInAccess::ATTRIBUTE_NAME, json_encode(['note' => 'kept']));
        $this->gatedVariant->variantWarehouses()->each(function ($row): void {
            $row->config = ['rates' => ['daily' => 5], 'other' => true];
            $row->save();
        });

        $this->runSetup();
        $this->runSetup(['--hourly' => 1.5, '--expire-minutes' => 45]);

        $this->assertCount(1, $this->ruleNamed(SetupWalkInParkingAction::EXIT_DECISION_RULE_NAME)->get());
        $this->assertCount(1, $this->ruleNamed(SetupWalkInParkingAction::PASS_SCANNED_RULE_NAME)->get());

        $config = $this->gatedVariant->variantWarehouses()->first()->refresh()->config;
        $this->assertSame(1.5, $config['rates']['hourly']);
        $this->assertSame(5, $config['rates']['daily']);
        $this->assertTrue($config['other']);

        $attribute = $this->gatedProduct->refresh()->getAttributeByName(WalkInAccess::ATTRIBUTE_NAME)->value;
        $this->assertSame('kept', $attribute['note']);
        $this->assertSame(45, $attribute['expire_unused_minutes']);

        $orderType->refresh();
        $this->assertSame(['other', $this->gatedProduct->productsType->slug], $orderType->config[OrderTypes::EVENT_BOOKING]['product_types']);
        $this->assertSame(['x' => 1], $orderType->config['kept']);
    }

    public function testRunningAgainRepairsRulesFlippedToTheWrongMode(): void
    {
        $this->preparedProduct();
        $this->runSetup();
        $this->ruleNamed(SetupWalkInParkingAction::EXIT_DECISION_RULE_NAME)->update(['is_async' => 1]);
        $this->ruleNamed(SetupWalkInParkingAction::PASS_SCANNED_RULE_NAME)->update(['is_async' => 0]);

        $this->runSetup();

        $this->assertFalse((bool) $this->ruleNamed(SetupWalkInParkingAction::EXIT_DECISION_RULE_NAME)->sole()->is_async);
        $this->assertTrue((bool) $this->ruleNamed(SetupWalkInParkingAction::PASS_SCANNED_RULE_NAME)->sole()->is_async);
    }

    public function testItPrintsTheChecklistAndWarnsWithoutFailing(): void
    {
        $this->preparedProduct();

        $this->assertSame(0, $this->runSetup());

        $output = Artisan::output();
        $this->assertStringContainsString('Company timezone', $output);
        $this->assertStringContainsString('Movipass order-updated rule', $output);
        $this->assertStringContainsString('Movipass integration', $output);
        $this->assertStringContainsString(SetupWalkInParkingAction::CHECK_WARN, $output);
    }

    #[Group('serial')]
    public function testItFailsClearlyWhenTheActivitiesAreNotSynced(): void
    {
        $this->preparedProduct();
        Action::where('model_name', ParkingExitDecisionActivity::class)->update(['model_name' => 'unsynced']);

        $this->assertSame(1, $this->runSetup());

        $this->assertStringContainsString('kanvas:workflow-sync-actions', Artisan::output());
        $this->assertCount(0, $this->ruleNamed(SetupWalkInParkingAction::PASS_SCANNED_RULE_NAME)->get());
    }

    private function preparedProduct(): OrderTypes
    {
        $this->buildWalkInParking(walkIn: false);

        return $this->configureOwnedWalkInOrderType([
            OrderTypes::EVENT_BOOKING => ['product_types' => ['other']],
            'kept' => ['x' => 1],
        ]);
    }

    private function runSetup(array $options = []): int
    {
        return Artisan::call('kanvas-movipass:setup-walk-in-parking', [
            'app_id' => $this->apps->getId(),
            'product_id' => $this->gatedProduct->getId(),
            ...$options,
        ]);
    }

    private function ruleNamed(string $name)
    {
        return Rule::query()->where('apps_id', $this->apps->getId())->where('name', $name);
    }
}
