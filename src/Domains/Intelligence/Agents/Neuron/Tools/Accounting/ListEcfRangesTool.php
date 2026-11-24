<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalRangeStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalNumberRange;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Own company only: ranges are a per-company DGII authorization, never read across a read grant.
 */
#[AgentTool(name: 'List e-NCF Ranges', category: 'accounting')]
class ListEcfRangesTool extends Tool
{
    use HasKanvasContext;
    use ReportsToolOutcome;

    private const int MAX_ROWS = 100;

    protected string $name = 'list_ecf_ranges';

    protected ?string $description = 'The e-NCF ranges loaded from DGII for this company, newest first: e-CF type, first and last '
        . 'e-NCF, the next number to be issued, how many are left, expiry and status (active, exhausted, expired, '
        . 'superseded). Use it to see which types are covered, which are running low or close to expiry, and before '
        . 'loading a renewal with configure_ecf_range.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'active_only',
                type: PropertyType::BOOLEAN,
                description: 'True to list only the ranges in use now; leave out to include the history.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?bool $active_only = null): array
    {
        $ranges = FiscalNumberRange::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->when($active_only === true, fn ($query) => $query->where('status', FiscalRangeStatusEnum::ACTIVE->value))
            ->with('sequence')
            ->newestFirst()
            ->limit(self::MAX_ROWS)
            ->get();

        if ($ranges->isEmpty()) {
            return $this->noop(
                ['count' => 0, 'ranges' => []],
                'No e-NCF range is loaded yet. Ask the person for the ranges on their DGII authorization and load each with configure_ecf_range; do not call this again.',
            );
        }

        return $this->ok([
            'count' => $ranges->count(),
            'ranges' => $ranges->map(fn (FiscalNumberRange $range): array => $range->describe())->values()->all(),
        ]);
    }
}
