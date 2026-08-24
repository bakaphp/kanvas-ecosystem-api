<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Rules\DataTransferObject\RuleConditionData;
use Kanvas\Workflow\Rules\Enums\RuleConditionOperatorEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleType;
use Throwable;

/**
 * The bits of rule assembly the create and update tools must agree on. Two copies of condition
 * parsing would drift, and a rule edited under different rules than it was created under is how a
 * condition quietly changes meaning.
 */
trait AssemblesWorkflowRuleForTool
{
    /**
     * @return array{conditions: list<RuleConditionData>, error?: string}
     */
    protected function parseConditions(?string $conditions): array
    {
        $conditions = trim((string) $conditions);

        if ($conditions === '') {
            return ['conditions' => []];
        }

        $parsed = [];
        foreach (preg_split('/[|\n]/', $conditions) ?: [] as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            // Word operators are matched first and only when whitespace-delimited: an attribute such as
            // "min" contains "in", so a combined alternation would read "min > 3" as "m in > 3".
            // Symbolic operators are alternated longest-first so ">=" isn't cut down to ">".
            $wordOperators = '/^(?<attribute>.+?)\s+(?<operator>not in|in|matches)\s+(?<value>.+)$/i';
            $symbolOperators = '/^(?<attribute>.+?)\s*(?<operator>>=|<=|!=|==|=|>|<)\s*(?<value>.*)$/';

            if (! preg_match($wordOperators, $entry, $matches) && ! preg_match($symbolOperators, $entry, $matches)) {
                return [
                    'conditions' => [],
                    'error' => sprintf(
                        'Could not read the condition "%s". Write each one as "attribute operator value", e.g. '
                        . '"status == new", and separate them with "|".',
                        $entry
                    ),
                ];
            }

            $operator = mb_strtolower(trim($matches['operator']));
            $operator = $operator === '=' ? '==' : $operator;
            $value = trim(trim($matches['value']), '\'"');

            $parsed[] = new RuleConditionData(
                attribute_name: trim($matches['attribute']),
                operator: RuleConditionOperatorEnum::from($operator),
                value: $value === '' ? null : $value,
            );
        }

        return ['conditions' => $parsed];
    }

    protected function buildPattern(int $conditionCount): string
    {
        return $conditionCount === 0 ? '1' : implode(' AND ', range(1, $conditionCount));
    }

    /**
     * What the chosen steps accept versus what was actually supplied. The wording of the refusal is
     * left to the caller — creating and updating fail for the same reasons but need different advice,
     * and `params` REPLACES on update, which the model has to be told.
     *
     * `known` stays empty when no step documented its params: an undocumented step accepts anything
     * rather than rejecting settings it simply never described.
     *
     * @param array<string, mixed> $params
     * @param list<Action> $actions
     * @return array{known: array<string, string>, unknown: list<string>, missing: array<string, string>}
     */
    protected function auditParams(array $params, array $actions): array
    {
        $known = [];
        $missing = [];

        foreach ($actions as $action) {
            $known += $action->paramDescriptions();

            foreach ($action->requiredParamNames() as $required) {
                if (! array_key_exists($required, $params)) {
                    $missing[$required] = $action->name;
                }
            }
        }

        return [
            'known' => $known,
            'unknown' => $known === [] ? [] : array_values(array_diff(array_keys($params), array_keys($known))),
            'missing' => $missing,
        ];
    }

    /**
     * @param list<RuleConditionData> $conditions
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function conditionWarnings(SystemModules $module, array $conditions, array $params): array
    {
        $warnings = $this->warnUnknownConditionAttributes($module, $conditions, $params);

        return $warnings === [] ? [] : ['warnings' => $warnings];
    }

    /**
     * Every rule carries at least one condition.
     *
     * A rule with none leaves the pattern as the bare literal `1`, which the expression language
     * happens to evaluate truthy — so it works by accident rather than by statement, and nothing in
     * the rule says what it matches. `id > 0` is true for any persisted record, so it changes no
     * behaviour; it just makes "runs on everything" something the rule actually says, and keeps every
     * rule editable the same way.
     *
     * @param list<RuleConditionData> $conditions
     * @return list<RuleConditionData>
     */
    protected function withDefaultCondition(array $conditions): array
    {
        if ($conditions !== []) {
            return $conditions;
        }

        return [
            new RuleConditionData(
                attribute_name: 'id',
                operator: RuleConditionOperatorEnum::from('>'),
                value: '0',
            ),
        ];
    }

    /**
     * A trigger is fired on one kind of record, and rules are matched against that record's class —
     * so a rule pairing a trigger with the wrong entity is never even considered. It saves cleanly,
     * reads correctly, and does nothing. `after-adding-message-to-channel` fires on the CHANNEL, and
     * "message" in its name makes Message the obvious-looking and wrong choice.
     *
     * Nothing in `rules_types` records what a trigger fires on, so the evidence is what the tenant's
     * existing rules already do. Two or more rules agreeing is treated as a convention worth
     * enforcing; a single one is only worth mentioning, since somebody has to write the first rule
     * for any new pairing.
     *
     * @return array{message: string, refuse: bool}|null
     */
    protected function checkTriggerEntityFit(RuleType $ruleType, SystemModules $module): ?array
    {
        $chosen = (string) $module->model_name;

        if ($chosen === '') {
            return null;
        }

        $moduleIds = Rule::query()
            ->where('rules_types_id', $ruleType->getId())
            ->where('is_deleted', 0)
            ->pluck('systems_modules_id')
            ->all();

        if ($moduleIds === []) {
            return null;
        }

        // Compared by CLASS, never by module id. `system_modules` is per-app, so every app has its own
        // row for the same model — comparing ids reports a mismatch between an entity and itself the
        // moment the evidence comes from another app, which is most of the time.
        $counts = [];

        foreach (SystemModules::query()->whereIn('id', $moduleIds)->get(['id', 'model_name']) as $used) {
            $counts[(string) $used->model_name] = ($counts[(string) $used->model_name] ?? 0)
                + count(array_keys($moduleIds, $used->getId(), true));
        }

        unset($counts[$chosen]);

        if ($counts === []) {
            return null;
        }

        arsort($counts);
        $dominant = (string) array_key_first($counts);
        $agreement = $counts[$dominant];

        return [
            'message' => sprintf(
                'The "%s" trigger fires on %s in every other workflow that uses it (%d of them), not on '
                . '%s. A rule whose entity does not match what the trigger fires on is never matched, so '
                . 'it would save correctly and never run.',
                $ruleType->name,
                class_basename($dominant),
                $agreement,
                class_basename($chosen)
            ),
            'refuse' => $agreement >= 2,
        ];
    }

    /**
     * Flag condition attributes that exist nowhere — a rule with `is_publish == 1` on a table whose
     * column is `is_public` is valid, saves cleanly, and matches nothing forever.
     *
     * A warning rather than a refusal: conditions are evaluated against the rule's params merged with
     * the record, so an attribute can legitimately come from a param supplied at runtime and never
     * appear as a column. Refusing those would block a working pattern; saying nothing lets a typo
     * pass as a rule that simply never fires.
     *
     * @param list<RuleConditionData> $conditions
     * @param array<string, mixed> $params
     * @return list<string>
     */
    protected function warnUnknownConditionAttributes(
        SystemModules $module,
        array $conditions,
        array $params = []
    ): array {
        if ($conditions === []) {
            return [];
        }

        $columns = $this->entityColumns($module);

        if ($columns === []) {
            return [];
        }

        $unknown = [];

        foreach ($conditions as $condition) {
            $attribute = $condition->attribute_name;

            if (in_array($attribute, $columns, true) || array_key_exists($attribute, $params)) {
                continue;
            }

            $unknown[] = $attribute;
        }

        if ($unknown === []) {
            return [];
        }

        return [sprintf(
            '%s not a field on %s and not one of this workflow\'s params, so %s never match. Check the '
            . 'spelling against: %s',
            count($unknown) === 1 ? sprintf('"%s" is', $unknown[0]) : sprintf('"%s" are', implode('", "', $unknown)),
            $module->name,
            count($unknown) === 1 ? 'it will' : 'they will',
            implode(', ', array_slice($columns, 0, 40))
        )];
    }

    /**
     * @return list<string>
     */
    protected function entityColumns(SystemModules $module): array
    {
        $className = (string) $module->model_name;

        if ($className === '' || ! class_exists($className)) {
            return [];
        }

        try {
            $model = new $className();

            if (! $model instanceof Model) {
                return [];
            }

            return Schema::connection($model->getConnectionName())->getColumnListing($model->getTable());
        } catch (Throwable) {
            // A model that cannot be introspected simply gets no check — better than blocking the rule.
            return [];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array{params: array<string, mixed>, error?: string}
     */
    protected function parseParams(?string $params): array
    {
        $params = trim((string) $params);

        if ($params === '') {
            return ['params' => []];
        }

        $decoded = json_decode($params, true);

        if (! is_array($decoded)) {
            return [
                'params' => [],
                'error' => 'params must be a JSON object, e.g. {"message_type_id": 42, "status": "pending"}.',
            ];
        }

        if (array_is_list($decoded)) {
            return [
                'params' => [],
                'error' => 'params must be a JSON object of name/value pairs, not a list.',
            ];
        }

        return ['params' => $decoded];
    }
}
