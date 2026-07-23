<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Connectors\Acumatica\Actions\VoidApBillAction;
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

/**
 * Voids a previously-pushed AP bill in Acumatica — the cleanup counterpart to CreateApBillTool. Creates
 * and releases a Debit Adjustment applied against the bill's full outstanding balance, closing both
 * documents (the API equivalent of the manual Reverse -> APPLY -> Load Documents -> Release flow).
 *
 * @see \Kanvas\Connectors\Acumatica\Actions\VoidApBillAction — the actual void.
 */
#[AgentTool(name: 'Void AP Bill')]
class VoidApBillTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'void_ap_bill',
            description: 'Voids a previously-pushed AP bill in Acumatica by creating and releasing a Debit '
                . 'Adjustment against its full outstanding balance, closing both documents. Bypasses the normal '
                . 'human approval gate — use only when the user explicitly asks to void a bill this way.',
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
                type: PropertyType::NUMBER,
                description: 'The Kanvas bill id to void (returned as bill_id by create_ap_bill).',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $bill_id): array
    {
        $app = $this->app;

        $bill = Bill::query()
            ->where('id', $bill_id)
            ->where('apps_id', $app->getId())
            ->where('companies_id', $this->company->getId())
            ->first();

        if ($bill === null) {
            return [
                'voided' => false,
                'reason' => 'bill_not_found',
                'message' => "No bill with id {$bill_id} for this app/company.",
            ];
        }

        try {
            $voidRef = new VoidApBillAction($bill)->execute();
        } catch (AcumaticaWriteException|Throwable $e) {
            return [
                'voided' => false,
                'bill_id' => $bill->getId(),
                'bill_ref' => (string) $bill->get(AcumaticaCustomFieldEnum::BILL_REF->value, ''),
                'reason' => 'void_failed',
                'message' => 'Voiding the bill in Acumatica failed: ' . $e->getMessage(),
            ];
        }

        return [
            'voided' => true,
            'bill_id' => $bill->getId(),
            'bill_ref' => (string) $bill->get(AcumaticaCustomFieldEnum::BILL_REF->value, ''),
            'void_ref' => $voidRef,
            'next' => 'A Debit Adjustment was created and released against the bill in Acumatica — both '
                . 'documents should now show Closed with a zero balance.',
        ];
    }
}
