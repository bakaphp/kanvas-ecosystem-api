<?php

declare(strict_types=1);

namespace App\Console\Commands\Workflows;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleType;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;
use Laravel\Prompts\Exceptions\NonInteractiveValidationException;

use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;

use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

use RuntimeException;
use Symfony\Component\Console\Exception\InvalidArgumentException;

class CreateEntityWorkflowCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:create-workflow {app_id} ';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Command description';

    /**
     * @psalm-suppress MixedArgument
     *
     * @throws InvalidArgumentException
     * @throws ModelNotFoundException
     * @throws RuntimeException
     * @throws NonInteractiveValidationException
     */
    public function handle(): void
    {
        $app = Apps::getById($this->argument('app_id'));

        $ruleName = text('What is the name for the workflow?');
        $description = text('What is the description for the workflow?');
        $ruleType = select(
            label: 'What is the rule type?',
            options: RuleType::pluck('name', 'id'),
            scroll: 5
        );
        $ruleParam = text('What is the rule param?');

        $systemModule = select(
            label: 'What is the system module?',
            options: SystemModules::fromApp($app)->pluck('name', 'id'),
            scroll: 5
        );

        $companyId = text('company id?', '0', '0');

        $rule = Rule::firstOrCreate([
            'name' => $ruleName,
            'rules_types_id' => $ruleType,
            'systems_modules_id' => $systemModule,
            'apps_id' => $app->getId(),
            'companies_id' => $companyId,
        ], [
            'description' => $description,
            'params' => $ruleParam,
            'pattern' => 1,
            'is_deleted' => 0,
        ]);

        info('Rule created successfully - ' . $rule->getId() . ' - ' . $rule->name);

        info('Now lets setup when to run the rule');

        //now rule conditional
        $attribute = text('What is the attribute name?');
        $operator = select(
            label: 'What is the operator?',
            options: [
                '==' => '==',
                '!=' => '!=',
                '>' => '>',
                '<' => '<',
                '>=' => '>=',
                '<=' => '<=',
            ],
            scroll: 5
        );
        $attributeValue = text('What is the attribute value?', '0', '0');

        $rule->getRulesConditions()->firstOrCreate([
            'attribute_name' => $attribute,
            'operator' => $operator,
            'value' => $attributeValue,
        ], [
            'is_deleted' => 0,
        ]);

        //now rules worklfow actions
        info('Now lets setup the workflow actions');

        $actions = multiselect(
            label: 'What actions should be assigned?',
            options: Action::pluck('name', 'id'),
            scroll: 5
        );

        $weight = 0;
        foreach ($actions as $action) {
            $ruleWorkflowAction = RuleWorkflowAction::firstOrCreate([
                'system_modules_id' => $systemModule,
                'actions_id' => $action,
            ], [
                'is_deleted' => 0,
            ]);

            RuleAction::firstOrCreate([
                'rules_id' => $rule->getId(),
                'rules_workflow_actions_id' => $ruleWorkflowAction->getId(),
            ], [
                'weight' => $weight,
                'is_deleted' => 0,
            ]);

            $weight++;
        }

        info('Rule assigned actions successfully');
        table(
            ['rule', 'action', 'weight'],
            RuleAction::where('rules_id', $rule->getId())->get()->map(function ($ruleAction) {
                return [
                    $ruleAction->rules->name,
                    $ruleAction->activity->action->name,
                    $ruleAction->weight,
                ];
            })
        );
    }
}
