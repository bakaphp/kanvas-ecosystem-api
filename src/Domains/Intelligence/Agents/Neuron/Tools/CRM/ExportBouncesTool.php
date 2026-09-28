<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Connectors\Apollo\Services\CsvExportService;
use Kanvas\Connectors\Apollo\Services\PeopleBouncesFeedService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Generates a downloadable CSV of every dead/bouncing email and returns a file reference
 * (never inline rows). Scoped to the agent's current company.
 */
#[AgentTool(name: 'Export Bounces', category: 'crm')]
class ExportBouncesTool extends Tool
{
    use HasKanvasContext;

    private const array STATUS_LABEL = [
        'valid' => 'Valid',
        'soft_bounce' => 'At risk',
        'hard_bounce' => 'Bounced',
        'invalid' => 'Invalid',
    ];

    public function __construct()
    {
        parent::__construct(
            name: 'export_bounces',
            description: 'Generates a downloadable CSV of every dead / bouncing email (hard bounce / invalid). '
                . 'Returns a file URL and row count. Use for "export the bad emails", "download the bounce list". '
                . 'granularity picks per_email (one row per bad address) or per_person; pass include_soft_bounce '
                . 'to also include recoverable soft bounces.',
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
                name: 'granularity',
                type: PropertyType::STRING,
                description: 'One row "per_email" (default — a person with 2 bad addresses yields 2 rows) or "per_person".',
                required: false,
                enum: ['per_email', 'per_person'],
            ),
            new ToolProperty(
                name: 'include_soft_bounce',
                type: PropertyType::BOOLEAN,
                description: 'When true, also include recoverable soft bounces. Defaults to false (permanent failures only).',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $granularity = null, ?bool $include_soft_bounce = null): array
    {
        $granularity = $granularity === 'per_person' ? 'per_person' : 'per_email';

        $rows = new PeopleBouncesFeedService($this->app, $this->company)->rows(
            includeSoftBounce: (bool) $include_soft_bounce,
            granularity: $granularity,
        );

        $headers = ['CRM', 'Person', 'Company', 'Email', 'Status', 'Bounced On'];

        $csvRows = array_map(
            fn (array $row): array => [
                $row['crm'],
                $row['person'],
                $row['company'],
                $row['email'],
                self::STATUS_LABEL[$row['status']] ?? $row['status'],
                $row['bounced_at'] ?? '',
            ],
            $rows,
        );

        return app(CsvExportService::class)->export(
            $this->app,
            $this->company,
            $this->user,
            'bounces',
            $headers,
            $csvRows,
        );
    }
}
