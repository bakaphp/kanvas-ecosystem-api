<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Services;

use Kanvas\Workflow\Rules\Enums\RuleConditionOperatorEnum;

/**
 * Evaluates a policy's `when` / `trigger_condition` shape — {field, operator, value} — against the
 * entity's data.
 *
 * Deliberately reuses RuleConditionOperatorEnum rather than inventing a second operator vocabulary:
 * anyone who already writes workflow rule conditions can read an approval policy without learning
 * new syntax.
 */
class ApprovalConditionEvaluatorService
{
    /**
     * A condition that is absent or malformed matches. A policy step with no `when` always applies,
     * and a typo must not silently drop a step out of an approval chain — an over-broad chain is
     * reviewable, a missing one is invisible.
     *
     * @param array<string, mixed>|null $condition
     * @param array<string, mixed> $data
     */
    public function matches(?array $condition, array $data): bool
    {
        $field = $condition['field'] ?? null;

        if ($field === null || ! is_string($field) || $field === '') {
            return true;
        }

        $operator = RuleConditionOperatorEnum::tryFrom(
            strtolower(trim((string) ($condition['operator'] ?? '==')))
        ) ?? RuleConditionOperatorEnum::EQUAL;

        return $this->compare($this->valueAt($data, $field), $operator, $condition['value'] ?? null);
    }

    private function compare(mixed $actual, RuleConditionOperatorEnum $operator, mixed $expected): bool
    {
        return match ($operator) {
            RuleConditionOperatorEnum::EQUAL => $actual == $expected,
            RuleConditionOperatorEnum::NOT_EQUAL => $actual != $expected,
            RuleConditionOperatorEnum::GREATER_THAN => $this->numeric($actual) > $this->numeric($expected),
            RuleConditionOperatorEnum::GREATER_THAN_OR_EQUAL => $this->numeric($actual) >= $this->numeric($expected),
            RuleConditionOperatorEnum::LESS_THAN => $this->numeric($actual) < $this->numeric($expected),
            RuleConditionOperatorEnum::LESS_THAN_OR_EQUAL => $this->numeric($actual) <= $this->numeric($expected),
            RuleConditionOperatorEnum::IN => in_array($actual, (array) $expected, false),
            RuleConditionOperatorEnum::NOT_IN => ! in_array($actual, (array) $expected, false),
            RuleConditionOperatorEnum::MATCHES => $this->matchesPattern($actual, $expected),
        };
    }

    private function numeric(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * An invalid pattern is a policy typo, not a reason to abort the caller — treat it as no match.
     */
    private function matchesPattern(mixed $actual, mixed $expected): bool
    {
        if (! is_string($expected) || $expected === '') {
            return false;
        }

        return @preg_match($expected, (string) $actual) === 1;
    }

    /**
     * Dot notation so a condition can reach into the frozen payload: `payload.total_native`.
     */
    private function valueAt(array $data, string $field): mixed
    {
        $value = $data;

        foreach (explode('.', $field) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
