<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\TeeTime;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\TeeTime\Workflows\Activities\ActivateBookingOnPaymentActivity;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleType;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;

class SetupBookingActivationRuleCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:teetime-setup-booking-rule {app_id} {--company=0}';

    protected $description = 'Wire the workflow rule that runs ActivateBookingOnPaymentActivity when a booking order is paid (after-payment-intent).';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $action = Action::where('model_name', ActivateBookingOnPaymentActivity::class)->first();
        if (! $action) {
            $this->error('Action row not found. Run `php artisan kanvas:workflow-sync-actions` first to register the activity.');

            return self::FAILURE;
        }

        $systemModule = SystemModulesRepository::getByModelName(Order::class, $app);
        $ruleType = RuleType::where('name', WorkflowEnum::AFTER_PAYMENT_INTENT->value)->firstOrFail();
        $companyId = (int) $this->option('company');

        $rule = Rule::firstOrCreate(
            [
                'name' => 'TeeTime: activate booking on payment',
                'rules_types_id' => $ruleType->getId(),
                'systems_modules_id' => $systemModule->getId(),
                'apps_id' => $app->getId(),
                'companies_id' => $companyId,
            ],
            [
                'description' => 'Generates passes and sends the booking email when a booking order is paid.',
                // pattern '1' with no conditions evaluates truthy — the activity self-guards on event type + entity.
                'pattern' => 1,
                'is_deleted' => 0,
            ]
        );

        $ruleWorkflowAction = RuleWorkflowAction::firstOrCreate(
            [
                'system_modules_id' => $systemModule->getId(),
                'actions_id' => $action->getId(),
            ],
            [
                'is_deleted' => 0,
            ]
        );

        RuleAction::firstOrCreate(
            [
                'rules_id' => $rule->getId(),
                'rules_workflow_actions_id' => $ruleWorkflowAction->getId(),
            ],
            [
                'weight' => 0,
                'is_deleted' => 0,
            ]
        );

        $this->info(sprintf(
            'Rule wired: #%d (%s) on Order/after-payment-intent for app %d, company %d.',
            $rule->getId(),
            $rule->name,
            $app->getId(),
            $companyId
        ));

        return self::SUCCESS;
    }
}
