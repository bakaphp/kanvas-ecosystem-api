<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\RuleTypeEnum;
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
            $ruleType = RuleType::getByName(RuleTypeEnum::CREATED->value);
        } catch(Throwable $e) {
            $ruleType = RuleType::factory()->create();
        }

        return [
            'systems_modules_id' => SystemModulesRepository::getByModelName(Lead::class),
            'companies_id' => 1,
            'apps_id' => $app->getId(),
            'rules_types_id' => $ruleType->getId(),
            'name' => 'Lead Zoho Test',
            'description' => 'Lead Zoho Test',
            'pattern' => 1,
            'params' => ['test' => 'test'],
            'is_async' => false,
        ];
    }
}
