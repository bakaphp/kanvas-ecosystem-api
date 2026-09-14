<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools\Traits;

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
        $guidance = trim((string) $guidance);

        return [
            ...$payload,
            'outcome' => $outcome->value,
            'note' => $guidance !== ''
                ? $guidance . ' ' . $outcome->guidance()
                : $outcome->guidance(),
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

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function ok(array $payload = [], ?string $guidance = null): array
    {
        return $this->withOutcome(ToolOutcomeEnum::OK, ['success' => true, ...$payload], $guidance);
    }

    /**
     * The write was refused. Carries `success: false` because a model that reads only an `error` key
     * still narrates the write as done — the incident this exists for: an agent was told it could not
     * edit a template it did not own and reported back that it had updated it.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function denied(string $error, array $payload = [], ?string $guidance = null): array
    {
        return $this->withOutcome(
            ToolOutcomeEnum::DENIED,
            ['success' => false, 'error' => $error, ...$payload],
            $guidance
        );
    }

    /**
     * The write blew up on something outside the tool's control — a downstream service, a DB write,
     * an unexpected throw. Same `success: false` + `error` shape as `denied()`, so a model cannot
     * narrate it as the happy path; the difference is only which sentence the outcome carries.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function failed(string $error, array $payload = [], ?string $guidance = null): array
    {
        return $this->withOutcome(
            ToolOutcomeEnum::PROVIDER_ERROR,
            ['success' => false, 'error' => $error, ...$payload],
            $guidance
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function invalidArgs(string $error, array $payload = [], ?string $guidance = null): array
    {
        return $this->withOutcome(
            ToolOutcomeEnum::INVALID_ARGS,
            ['success' => false, 'error' => $error, ...$payload],
            $guidance
        );
    }
}
