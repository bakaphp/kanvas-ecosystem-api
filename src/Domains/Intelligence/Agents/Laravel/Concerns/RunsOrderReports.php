<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Concerns;

use InvalidArgumentException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Enums\ToolOutcomeEnum;
use Laravel\Ai\Tools\Request;

trait RunsOrderReports
{
    /**
     * Raw strings only; `OrderReportService` trims and drops blanks for every caller.
     *
     * @return list<string>
     */
    protected function names(Request $request, string $key): array
    {
        return array_map(fn (mixed $name): string => (string) $name, array_values((array) ($request[$key] ?? [])));
    }

    /**
     * An unknown order type, tag or timezone is answered as an error naming the valid values, so the
     * model corrects the next call instead of the turn dying on a thrown exception.
     *
     * @param callable(): array<string, mixed> $report
     */
    protected function report(callable $report): string
    {
        try {
            $payload = $report();
        } catch (ValidationException|InvalidArgumentException $e) {
            $payload = [
                'success' => false,
                'outcome' => ToolOutcomeEnum::INVALID_ARGS->value,
                'error' => $e->getMessage(),
                'note' => ToolOutcomeEnum::INVALID_ARGS->guidance(),
            ];
        }

        return (string) json_encode($payload, JSON_PRETTY_PRINT);
    }
}
