<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Bills\Models\Bill;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/** Appends an internal note to an AP bill in the Kanvas ledger. */
#[AgentTool(name: 'Add Bill Note', category: 'accounting')]
class AddBillNoteTool extends Tool
{
    use HasKanvasContext;

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
        $bill = Bill::query()
            ->where('id', $bill_id)
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->first();

        if ($bill === null) {
            return [
                'note_added' => false,
                'reason' => 'bill_not_found',
                'message' => "No bill with id {$bill_id} for this app/company.",
            ];
        }

        $stamped = '[' . Carbon::now()->toDateTimeString() . '] ' . $note;
        $bill->internal_notes = $bill->internal_notes !== null && $bill->internal_notes !== ''
            ? $bill->internal_notes . "\n" . $stamped
            : $stamped;
        $bill->saveOrFail();

        return [
            'note_added' => true,
            'bill_id' => $bill->getId(),
            'note' => $stamped,
        ];
    }
}
