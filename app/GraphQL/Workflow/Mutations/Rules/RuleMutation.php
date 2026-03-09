<?php

declare(strict_types=1);

namespace App\GraphQL\Workflow\Mutations\Rules;

use Illuminate\Validation\ValidationException;
use Kanvas\Apps\Models\Apps;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Rules\Actions\CreateRuleAction;
use Kanvas\Workflow\Rules\Actions\UpdateRuleAction;
use Kanvas\Workflow\Rules\DataTransferObject\Rule as RuleData;
use Kanvas\Workflow\Rules\DataTransferObject\RuleActionData;
use Kanvas\Workflow\Rules\DataTransferObject\RuleConditionData;
use Kanvas\Workflow\Rules\Enums\RuleConditionOperatorEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleType;
use Spatie\LaravelData\DataCollection;

class RuleMutation
{
    /**
     * @psalm-suppress MixedArrayAccess
     */
    public function create(mixed $rootValue, array $request): Rule
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        /** @var array<string, mixed> $input */
        $input = $request['input'];

        /** @var RuleType $ruleType */
        $ruleType = RuleType::getById((int) $input['rules_types_id']);
        /** @var SystemModules $systemModule */
        $systemModule = SystemModules::getById((int) $input['systems_modules_id'], $app);

        $conditions = $this->buildConditions($input['conditions'] ?? null);
        $pattern = $this->resolvePattern($input['pattern'] ?? null, $conditions);

        return new CreateRuleAction(
            new RuleData(
                app: $app,
                company: $company,
                user: $user,
                ruleType: $ruleType,
                systemModule: $systemModule,
                name: $input['name'],
                description: $input['description'] ?? null,
                pattern: $pattern,
                params: $input['params'] ?? null,
                is_async: (bool) ($input['is_async'] ?? false),
                conditions: $conditions,
                actions: $this->buildActions($input['actions'] ?? null),
            ),
        )->execute();
    }

    /**
     * @psalm-suppress MixedArrayAccess
     */
    public function update(mixed $rootValue, array $request): Rule
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        /** @var array<string, mixed> $input */
        $input = $request['input'];

        /** @var Rule $rule */
        $rule = Rule::getById($request['id'], $app);

        /** @var RuleType $ruleType */
        $ruleType = isset($input['rules_types_id'])
            ? RuleType::getById((int) $input['rules_types_id'])
            : $rule->type;

        /** @var SystemModules $systemModule */
        $systemModule = isset($input['systems_modules_id'])
            ? SystemModules::getById((int) $input['systems_modules_id'], $app)
            : $rule->systemModule;

        $conditions = $this->buildConditions($input['conditions'] ?? null);
        $pattern = isset($input['conditions'])
            ? $this->resolvePattern($input['pattern'] ?? null, $conditions)
            : ($input['pattern'] ?? $rule->pattern);

        return new UpdateRuleAction(
            $rule,
            new RuleData(
                app: $app,
                company: $company,
                user: $user,
                ruleType: $ruleType,
                systemModule: $systemModule,
                name: $input['name'] ?? $rule->name,
                description: $input['description'] ?? $rule->description,
                pattern: $pattern,
                params: $input['params'] ?? $rule->params,
                is_async: (bool) ($input['is_async'] ?? $rule->is_async),
                conditions: $conditions,
                actions: $this->buildActions($input['actions'] ?? null),
            ),
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        $app = app(Apps::class);
        /** @var Rule $rule */
        $rule = Rule::getById($request['id'], $app);

        $rule->getRulesConditions()->update(['is_deleted' => 1]);
        $rule->workflowActivities()->update(['is_deleted' => 1]);

        return $rule->softDelete();
    }

    /**
     * Resolve and validate the pattern against the number of conditions.
     * If no pattern is provided, auto-generates "1 AND 2 AND ... AND N".
     */
    private function resolvePattern(?string $pattern, ?DataCollection $conditions): string
    {
        $conditionCount = $conditions !== null ? $conditions->count() : 0;

        if ($conditionCount === 0) {
            return $pattern ?? '1';
        }

        if ($pattern === null || $pattern === '') {
            return implode(' AND ', range(1, $conditionCount));
        }

        $this->validatePattern($pattern, $conditionCount);

        return $pattern;
    }

    /**
     * Validate that the pattern references exactly the condition numbers 1..N.
     */
    private function validatePattern(string $pattern, int $conditionCount): void
    {
        preg_match_all('/\b(\d+)\b/', $pattern, $matches);
        $referencedNumbers = array_unique(array_map('intval', $matches[1]));
        sort($referencedNumbers);

        $expectedNumbers = range(1, $conditionCount);

        if ($referencedNumbers !== $expectedNumbers) {
            throw ValidationException::withMessages([
                'pattern' => sprintf(
                    'Pattern must reference exactly conditions %s, got: %s',
                    implode(', ', $expectedNumbers),
                    implode(', ', $referencedNumbers)
                ),
            ]);
        }
    }

    /**
     * @psalm-suppress MixedArrayAccess, InvalidReturnType
     */
    private function buildConditions(?array $conditions): ?DataCollection
    {
        if ($conditions === null || $conditions === []) {
            return null;
        }

        $items = array_map(
            fn (array $c) => new RuleConditionData(
                attribute_name: $c['attribute_name'],
                operator: RuleConditionOperatorEnum::from($c['operator']),
                value: $c['value'] ?? null,
            ),
            $conditions
        );

        return RuleConditionData::collect($items, DataCollection::class);
    }

    /**
     * @psalm-suppress MixedArrayAccess, InvalidReturnType
     */
    private function buildActions(?array $actions): ?DataCollection
    {
        if ($actions === null || $actions === []) {
            return null;
        }

        $items = array_map(
            function (array $a) {
                /** @var Action $action */
                $action = Action::getById((int) $a['action_id']);

                return new RuleActionData(
                    action: $action,
                    weight: isset($a['weight']) ? (float) $a['weight'] : null,
                );
            },
            $actions
        );

        return RuleActionData::collect($items, DataCollection::class);
    }
}
