<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use App\Console\Commands\Connectors\Movipass\SetupMovipassSettleRuleCommand;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Workflows\Activities\SettleAuthorizedMovipassPaymentActivity;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleType;
use Tests\TestCase;

final class SetupMovipassSettleRuleCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'inventory', 'workflow', 'commerce'];

    private const string COMMAND = 'kanvas:movipass-setup-settle-rule';

    protected function setUp(): void
    {
        parent::setUp();

        Action::firstOrCreate(
            ['model_name' => SettleAuthorizedMovipassPaymentActivity::class],
            ['name' => 'Settle Movipass Authorized Payment']
        );
    }

    public function testWiresTheSettleRuleOnceAndRemoveRestoresIt(): void
    {
        $app = app(Apps::class);

        $this->artisan(self::COMMAND, ['app_id' => $app->getId()])->assertSuccessful();
        $this->artisan(self::COMMAND, ['app_id' => $app->getId()])->assertSuccessful();

        $rules = $this->rules($app, SetupMovipassSettleRuleCommand::RULE_NAME);
        $this->assertCount(1, $rules);
        $this->assertSame(0, (int) $rules->first()->is_deleted);
        $this->assertSame(
            [SettleAuthorizedMovipassPaymentActivity::class],
            $rules->first()->workflowActivities()->with('activity.action')->get()
                ->map(fn ($ruleAction) => $ruleAction->activity->action->model_name)
                ->all()
        );

        $this->artisan(self::COMMAND, ['app_id' => $app->getId(), '--remove' => true])->assertSuccessful();
        $this->assertSame(1, (int) $this->rules($app, SetupMovipassSettleRuleCommand::RULE_NAME)->first()->is_deleted);

        $this->artisan(self::COMMAND, ['app_id' => $app->getId()])->assertSuccessful();
        $this->assertCount(1, $this->rules($app, SetupMovipassSettleRuleCommand::RULE_NAME));
        $this->assertSame(0, (int) $this->rules($app, SetupMovipassSettleRuleCommand::RULE_NAME)->first()->is_deleted);
    }

    public function testRetiresTheLegacyPortalRule(): void
    {
        $app = app(Apps::class);
        $legacy = Rule::create([
            'name' => SetupMovipassSettleRuleCommand::LEGACY_RULE_NAME,
            'rules_types_id' => RuleType::firstOrCreate(['name' => WorkflowEnum::AFTER_PAYMENT_AUTHORIZED->value], ['is_deleted' => 0])->getId(),
            'systems_modules_id' => SystemModulesRepository::getByModelName(Order::class, $app)->getId(),
            'apps_id' => $app->getId(),
            'companies_id' => 0,
            'description' => 'legacy',
            'pattern' => 1,
            'params' => [],
            'is_deleted' => 0,
        ]);

        $this->artisan(self::COMMAND, ['app_id' => $app->getId()])->assertSuccessful();

        $this->assertSame(1, (int) $legacy->fresh()->is_deleted);
    }

    public function testForcesProcessorHoldOnThePasoRapidoOrderType(): void
    {
        $app = app(Apps::class);
        $orderType = new OrderTypes();
        $orderType->apps_id = $app->getId();
        $orderType->companies_id = auth()->user()->getCurrentCompany()->getId();
        $orderType->name = OrderTypeEnum::PASO_RAPIDO->value;
        $orderType->config = ['card_velocity' => ['max_cards_daily' => 3]];
        $orderType->saveOrFail();

        $this->artisan(self::COMMAND, ['app_id' => $app->getId()])->assertSuccessful();

        $orderType->refresh();
        $this->assertTrue($orderType->forcesProcessorHold());
        $this->assertSame(3, $orderType->cardVelocityLimit('max_cards_daily'));
    }

    private function rules(Apps $app, string $name): Collection
    {
        return Rule::query()
            ->where('name', $name)
            ->where('apps_id', $app->getId())
            ->where('systems_modules_id', SystemModulesRepository::getByModelName(Order::class, $app)->getId())
            ->whereHas('type', fn ($query) => $query->where('name', WorkflowEnum::AFTER_PAYMENT_AUTHORIZED->value))
            ->get();
    }
}
