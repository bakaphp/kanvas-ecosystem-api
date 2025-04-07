<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleType;
use Throwable;

class RuleFactory extends Factory
{
    protected $model = Rule::class;

    public function definition()
    {
        $app = app(Apps::class);

        try {
            $ruleType = RuleType::getByName(WorkflowEnum::CREATED->value);
        } catch (Throwable $e) {
            $ruleType = RuleType::factory()->create();
        }

        return [
            'systems_modules_id' => SystemModulesRepository::getByModelName(Lead::class),
            'companies_id' => AppEnums::GLOBAL_COMPANY_ID->getValue(),
            'apps_id' => $app->getId(),
            'rules_types_id' => $ruleType->getId(),
            'name' => 'Lead Zoho Test',
            'description' => 'Lead Zoho Test',
            'pattern' => 1,
            'params' => ['test' => 'test'],
            'is_async' => true,
        ];
    }

    public function withAsync(bool $isAsync)
    {
        return $this->state(function (array $attributes) use ($isAsync) {
            return [
                'is_async' => $isAsync,
            ];
        });
    }
}
