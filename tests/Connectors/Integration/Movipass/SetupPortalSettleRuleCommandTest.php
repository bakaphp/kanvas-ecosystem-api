<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use App\Console\Commands\Connectors\Movipass\SetupPortalSettleRuleCommand;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Workflows\Activities\SettlePortalPaymentActivity;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Tests\TestCase;

final class SetupPortalSettleRuleCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'inventory', 'workflow'];

    private const string COMMAND = 'kanvas:movipass-setup-portal-settle-rule';

    protected function setUp(): void
    {
        parent::setUp();

        Action::firstOrCreate(['model_name' => SettlePortalPaymentActivity::class], ['name' => 'Settle Movipass Portal Payment']);
    }

    public function testWiresTheSettleRuleOnceAndRemoveRestoresIt(): void
    {
        $app = app(Apps::class);

        $this->artisan(self::COMMAND, ['app_id' => $app->getId()])->assertSuccessful();
        $this->artisan(self::COMMAND, ['app_id' => $app->getId()])->assertSuccessful();

        $rules = $this->rules($app);
        $this->assertCount(1, $rules);
        $this->assertSame(0, (int) $rules->first()->is_deleted);
        $this->assertSame(
            [SettlePortalPaymentActivity::class],
            $rules->first()->workflowActivities()->with('activity.action')->get()
                ->map(fn ($ruleAction) => $ruleAction->activity->action->model_name)
                ->all()
        );

        $this->artisan(self::COMMAND, ['app_id' => $app->getId(), '--remove' => true])->assertSuccessful();
        $this->assertSame(1, (int) $this->rules($app)->first()->is_deleted);

        $this->artisan(self::COMMAND, ['app_id' => $app->getId()])->assertSuccessful();
        $this->assertCount(1, $this->rules($app));
        $this->assertSame(0, (int) $this->rules($app)->first()->is_deleted);
    }

    private function rules(Apps $app): Collection
    {
        return Rule::query()
            ->where('name', SetupPortalSettleRuleCommand::RULE_NAME)
            ->where('apps_id', $app->getId())
            ->where('systems_modules_id', SystemModulesRepository::getByModelName(Order::class, $app)->getId())
            ->whereHas('type', fn ($query) => $query->where('name', WorkflowEnum::AFTER_PAYMENT_AUTHORIZED->value))
            ->get();
    }
}
