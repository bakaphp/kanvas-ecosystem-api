<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Intelligence\Agents\Enums\ToolOutcomeEnum;

/**
 * Adds an outcome and its guidance to a tool's existing return shape, without changing that shape.
 *
 * Deliberately additive. Return dialects differ per tool family and the Agents guide says to stay
 * consistent within a family; normalising all of them would touch every tool and every PHP consumer
 * for a benefit no model can perceive, since no return schema is ever declared to a provider. What
 * the model reads is the value, so that is where the outcome goes.
 */
trait ReportsToolOutcome
{
    /**
     * @param array<string, mixed> $payload The tool's own result, in whatever dialect its family uses.
     * @param string|null $guidance Overrides the outcome's default sentence when a tool has something
     *        specific to say — a date range that exists, a field that would have matched.
     * @return array<string, mixed>
     */
    protected function withOutcome(
        ToolOutcomeEnum $outcome,
        array $payload = [],
        ?string $guidance = null,
    ): array {
        $note = trim($guidance ?? '') !== ''
            ? trim((string) $guidance) . ' ' . $outcome->guidance()
            : $outcome->guidance();

        return [
            ...$payload,
            'outcome' => $outcome->value,
            'note' => $note,
        ];
    }

    /**
     * The call succeeded and changed nothing. The single most useful case: it is what stops a model
     * reading a correct empty answer as a failed call and retrying until the run budget trips.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function noop(array $payload = [], ?string $guidance = null): array
    {
        return $this->withOutcome(ToolOutcomeEnum::NOOP, $payload, $guidance);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function notFound(array $payload = [], ?string $guidance = null): array
    {
        return $this->withOutcome(ToolOutcomeEnum::NOT_FOUND, $payload, $guidance);
    }
}
