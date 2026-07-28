<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Exporters;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;

/**
 * A per-domain source for the general export_records tool. Each record type (people, products,
 * employees, orders, …) implements one of these — the ONLY per-domain thing is which query + columns
 * a type maps to. The agent keeps a single export tool; adding a domain is a new implementation here,
 * registered in RecordExporterRegistry, with no change to the tool.
 *
 * A `rows()` implementation should throw Kanvas\Exceptions\ValidationException for bad/missing filters
 * (e.g. a required id) — the tool turns that into a clean LLM-facing error.
 */
interface RecordExporterInterface
{
    /** The record_type token the agent passes (snake_case, e.g. "event_participants"). */
    public function type(): string;

    /** One line describing which filters this type accepts, shown to the LLM. */
    public function filtersHint(): string;

    /**
     * @return list<string>
     */
    public function headers(): array;

    /**
     * @param array<string, mixed> $filters
     *
     * @return list<array<int, mixed>>
     */
    public function rows(Apps $app, Companies $company, array $filters): array;
}
