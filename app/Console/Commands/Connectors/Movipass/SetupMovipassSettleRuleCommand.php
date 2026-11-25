<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Movipass;

use App\Console\Commands\Concerns\WiresAppWorkflowRules;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Workflows\Activities\SettleAuthorizedMovipassPaymentActivity;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Models\Rule;

class SetupMovipassSettleRuleCommand extends Command
{
    use KanvasJobsTrait;
    use WiresAppWorkflowRules;

    public const string RULE_NAME = 'Movipass: settle authorized payment';

    public const string LEGACY_RULE_NAME = 'Movipass Portal: settle authorized payment';

    protected $signature = 'kanvas:movipass-setup-settle-rule {app_id} {--remove : Soft-delete the rule instead of wiring it}';

    protected $description = 'Wire (or remove) the after-payment-authorized rule that settles Movipass card payments (Portal or Azul hold) on an app, and force processor hold on its paso_rapido order type';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $this->removeRules($app, self::LEGACY_RULE_NAME);

        if ($this->option('remove')) {
            return $this->removeRules($app, self::RULE_NAME);
        }

        $wired = $this->wireAppRule(
            $app,
            SystemModulesRepository::getByModelName(Order::class, $app)->getId(),
            WorkflowEnum::AFTER_PAYMENT_AUTHORIZED->value,
            self::RULE_NAME,
            [SettleAuthorizedMovipassPaymentActivity::class],
        );

        $this->forcePasoRapidoHold($app);

        return $wired ? self::SUCCESS : self::FAILURE;
    }

    private function forcePasoRapidoHold(Apps $app): void
    {
        $orderTypes = OrderTypes::query()
            ->fromApp($app)
            ->notDeleted()
            ->where('name', OrderTypeEnum::PASO_RAPIDO->value)
            ->get();

        foreach ($orderTypes as $orderType) {
            $orderType->config = [...$orderType->config ?? [], OrderTypes::PROCESSOR_USE_HOLD => true];
            $orderType->save();
        }

        $this->info("Forced processor hold on {$orderTypes->count()} paso_rapido order type(s)");
    }

    private function removeRules(Apps $app, string $name): int
    {
        $removed = Rule::query()
            ->fromApp($app)
            ->notDeleted()
            ->where('name', $name)
            ->where('companies_id', 0)
            ->update(['is_deleted' => 1]);

        if ($removed > 0) {
            $this->info("Removed {$removed} rule(s) named '{$name}'");
        }

        return self::SUCCESS;
    }
}
