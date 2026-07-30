<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Apollo\Services\CsvExportService;
use Kanvas\Connectors\Apollo\Services\PeopleChangesFeedService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use Override;

/**
 * Generates a downloadable CSV of people changes and returns a file reference (never inline rows).
 * Scoped to the agent's current company.
 */
#[AgentTool(name: 'Export Changes', category: 'crm')]
class ExportChangesTool extends Tool
{
    use HasKanvasContext;

    private const array TYPE_LABEL = [
        'company' => 'Company',
        'title' => 'Title',
        'email' => 'Email',
        'promotion' => 'Promotion',
    ];

    public function __construct()
    {
        parent::__construct(
            name: 'export_changes',
            description: 'Generates a downloadable CSV of people changes (company / title / email / promotion) with '
                . 'before & after values. Returns a file URL and row count. Use when the user asks to "download", '
                . '"export", "give me a CSV/file/list" of changes. Filter by change_types (company, title, email, '
                . 'promotion) and an optional ISO date range.',
        );
    }

    /**
     * @return array<int, ToolPropertyInterface>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ArrayProperty(
                name: 'change_types',
                description: 'Optional list of change types to include. Omit for all types.',
                required: false,
                items: new ToolProperty(
                    name: 'change_type',
                    type: PropertyType::STRING,
                    description: 'One of: company, title, email, promotion.',
                    enum: ['company', 'title', 'email', 'promotion'],
                ),
            ),
            new ToolProperty(
                name: 'from',
                type: PropertyType::STRING,
                description: 'Optional range start as an ISO date (YYYY-MM-DD).',
                required: false,
            ),
            new ToolProperty(
                name: 'to',
                type: PropertyType::STRING,
                description: 'Optional range end as an ISO date (YYYY-MM-DD).',
                required: false,
            ),
        ];
    }

    /**
     * @param list<string>|null $change_types
     *
     * @return array<string, mixed>
     */
    public function __invoke(?array $change_types = null, ?string $from = null, ?string $to = null): array
    {
        $rows = new PeopleChangesFeedService($this->app, $this->company)->rows(
            changeTypes: $change_types,
            from: ! empty($from) ? Carbon::parse($from)->startOfDay() : null,
            to: ! empty($to) ? Carbon::parse($to)->endOfDay() : null,
        );

        $headers = ['CRM', 'Date', 'Person', 'Company', 'Change Type', 'Before', 'After', 'Deliverability'];

        $csvRows = array_map(
            fn (array $row): array => [
                $row['crm'],
                $row['occurred_at'],
                $row['person'],
                $row['company'],
                self::TYPE_LABEL[$row['type']] ?? $row['type'],
                $row['from'],
                $row['to'],
                $row['deliverability'],
            ],
            $rows,
        );

        return app(CsvExportService::class)->export(
            $this->app,
            $this->company,
            $this->user,
            'changes',
            $headers,
            $csvRows,
        );
    }
}
