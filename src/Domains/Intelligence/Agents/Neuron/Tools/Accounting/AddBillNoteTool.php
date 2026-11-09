<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesBankingRecordsForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/** Appends an internal note to an AP bill in the Kanvas ledger. */
#[AgentTool(name: 'Add Bill Note', category: 'accounting')]
class AddBillNoteTool extends Tool
{
    use HasKanvasContext;
    use ReportsToolOutcome;
    use ResolvesBankingRecordsForTool;
    use TrackByInputs;

    protected string $name = 'add_bill_note';

    protected ?string $description = 'Appends a timestamped internal note to an AP bill in the Kanvas ledger.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'bill_id',
                type: PropertyType::INTEGER,
                description: 'The Kanvas bill id to add the note to (returned as bill_id by create_ap_bill).',
                required: true,
            ),
            new ToolProperty(
                name: 'note',
                type: PropertyType::STRING,
                description: 'The note text to append.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $bill_id, string $note): array
    {
        $bill = $this->resolveBillOrError($bill_id);

        if (is_array($bill)) {
            return ['note_added' => false, ...$bill];
        }

        $stamped = $bill->appendInternalNote($note);
        $bill->saveOrFail();

        return [
            'note_added' => true,
            'bill_id' => $bill->getId(),
            'note' => $stamped,
        ];
    }
}
