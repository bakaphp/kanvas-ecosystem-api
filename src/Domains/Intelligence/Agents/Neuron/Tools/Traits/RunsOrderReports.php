<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Baka\Support\Str;
use InvalidArgumentException;
use Kanvas\Exceptions\ValidationException;

/**
 * Neuron's ToolProperty can't emit a JSON-schema `items` for an ARRAY param, so strict providers
 * (Gemini) reject it. Order-type and tag names travel as one comma-separated STRING and are split here.
 *
 * The host tool must also use `ReportsToolOutcome`, which supplies `invalidArgs()`.
 */
trait RunsOrderReports
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    abstract protected function invalidArgs(string $error, array $payload = [], ?string $guidance = null): array;

    /**
     * @return list<string>|null
     */
    protected function parseNames(?string $names): ?array
    {
        $list = Str::commaList($names);

        return $list === [] ? null : $list;
    }

    /**
     * An unknown order type, tag or timezone comes back as `invalid_args` with the valid values in the
     * message. Thrown, it would end the whole turn instead of correcting the next call.
     *
     * @param callable(): array<string, mixed> $report
     * @return array<string, mixed>
     */
    protected function runReport(callable $report): array
    {
        try {
            return $report();
        } catch (ValidationException|InvalidArgumentException $e) {
            return $this->invalidArgs($e->getMessage());
        }
    }
}
