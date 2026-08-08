<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Acumatica\Actions\PushBillNoteToAcumaticaAction;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum as AcumaticaCustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Bills\Models\Bill;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/** Appends a note to an already-pushed AP bill, in Kanvas and in Acumatica. */
#[AgentTool(name: 'Add Bill Note', category: 'accounting')]
class AddBillNoteTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'add_bill_note',
            description: 'Appends a note to an AP bill that has already been pushed to Acumatica — records it '
                . 'both in Kanvas and on the Acumatica document\'s Notes field.',
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

        $ref = (string) $bill->get(AcumaticaCustomFieldEnum::BILL_REF->value, '');

        if ($ref === '') {
            return [
                'note_added' => false,
                'reason' => 'bill_not_pushed',
                'message' => "Bill {$bill_id} hasn't been pushed to Acumatica yet — push it before adding a note.",
            ];
        }

        $stamped = '[' . Carbon::now()->toDateTimeString() . '] ' . $note;
        $bill->internal_notes = $bill->internal_notes !== null && $bill->internal_notes !== ''
            ? $bill->internal_notes . "\n" . $stamped
            : $stamped;
        $bill->saveOrFail();

        try {
            new PushBillNoteToAcumaticaAction($bill)->execute($stamped);
        } catch (AcumaticaWriteException|Throwable $e) {
            return [
                'note_added' => true,
                'pushed' => false,
                'bill_id' => $bill->getId(),
                'reason' => 'push_failed',
                'message' => 'Note saved in Kanvas but the push to Acumatica failed: ' . $e->getMessage(),
            ];
        }

        return [
            'note_added' => true,
            'pushed' => true,
            'bill_id' => $bill->getId(),
            'bill_ref' => $ref,
            'note' => $stamped,
        ];
    }
}
