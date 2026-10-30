<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Movipass;

use App\Console\Commands\Concerns\WiresAppWorkflowRules;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Workflows\Activities\SettlePortalPaymentActivity;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Models\Rule;

class SetupPortalSettleRuleCommand extends Command
{
    use KanvasJobsTrait;
    use WiresAppWorkflowRules;

    public const string RULE_NAME = 'Movipass Portal: settle authorized payment';

    protected $signature = 'kanvas:movipass-setup-portal-settle-rule {app_id} {--remove : Soft-delete the rule instead of wiring it}';

    protected $description = 'Wire (or remove) the after-payment-authorized rule that settles Movipass Portal payments on an app';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        if ($this->option('remove')) {
            return $this->removeRule($app);
        }

        $wired = $this->wireAppRule(
            $app,
            SystemModulesRepository::getByModelName(Order::class, $app)->getId(),
            WorkflowEnum::AFTER_PAYMENT_AUTHORIZED->value,
            self::RULE_NAME,
            [SettlePortalPaymentActivity::class],
        );

        return $wired ? self::SUCCESS : self::FAILURE;
    }

    private function removeRule(Apps $app): int
    {
        $removed = Rule::query()
            ->where('name', self::RULE_NAME)
            ->where('apps_id', $app->getId())
            ->where('companies_id', 0)
            ->where('is_deleted', 0)
            ->update(['is_deleted' => 1]);

        $this->info("Removed {$removed} rule(s)");

        return self::SUCCESS;
    }
}
