<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Common;

use Kanvas\Connectors\Apollo\Services\CsvExportService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Exporters\RecordExporterRegistry;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * General-purpose CSV exporter: turns a filtered list of records into a downloadable file and returns
 * a file reference (never inline rows). One cross-domain agent tool — the record types (people,
 * organizations, event participants, products, employees, orders, …) come from RecordExporterRegistry,
 * so a new domain is a new exporter class, not a new tool. Company-scoped. For the specialized
 * data-quality exports use export_changes / export_bounces.
 */
#[AgentTool(name: 'Export Records', category: 'common')]
class ExportRecordsTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'export_records',
            description: 'Generate a downloadable CSV of a list of records and return its file URL + row count. '
                . 'Use when the user asks to "download", "export", "give me a CSV/file/list" of any of these. '
                . 'record_type and its filters (pass filters as an object): '
                . new RecordExporterRegistry()->describe() . '.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'record_type',
                type: PropertyType::STRING,
                description: 'What to export.',
                required: true,
                enum: new RecordExporterRegistry()->types(),
            ),
            new ToolProperty(
                name: 'filters',
                type: PropertyType::OBJECT,
                description: 'Filter object for the chosen record_type (the record_type list in this tool\'s '
                    . 'description names the accepted keys per type). Pass {} for no filters.',
                required: false,
            ),
        ];
    }

    /**
     * @param array<string, mixed>|null $filters
     *
     * @return array<string, mixed>
     */
    public function __invoke(string $record_type, ?array $filters = null): array
    {
        $registry = new RecordExporterRegistry();
        $exporter = $registry->for($record_type);

        if ($exporter === null) {
            return ['error' => 'Unknown record_type "' . $record_type . '". Valid types: '
                . implode(', ', $registry->types()) . '.'];
        }

        try {
            $rows = $exporter->rows($this->app, $this->company, $filters ?? []);
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        return app(CsvExportService::class)->export(
            $this->app,
            $this->company,
            $this->user,
            $record_type,
            $exporter->headers(),
            $rows,
        );
    }
}
