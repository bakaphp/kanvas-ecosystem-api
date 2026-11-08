<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Services\ApprovalOriginService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesBankingRecordsForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesExpenseForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\ElectronicInvoicing\Actions\SubmitPurchaseEcfForApprovalAction;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfIssuanceService;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

#[AgentTool(name: 'Issue Purchase e-CF', category: 'accounting')]
class IssuePurchaseEcfTool extends Tool
{
    use HasKanvasContext;
    use ReportsToolOutcome;
    use ResolvesBankingRecordsForTool;
    use ResolvesExpenseForTool;
    use TrackByInputs;

    protected string $name = 'issue_purchase_ecf';

    protected ?string $description = 'Requests the e-CF the company must issue to DGII for a purchase or payment it '
        . 'already booked: E41 on a bill from a supplier who gives no e-CF (an individual with a cédula), E43 on a '
        . 'minor expense, E47 on a bill or expense paid to a foreign payee. It goes to a person for approval and is '
        . 'issued when they sign; you never issue it yourself. Pass exactly one of bill_id or expense_id. Never use it '
        . 'on a bill that came in as the vendor\'s own e-CF.';

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'ecf_type',
                type: PropertyType::STRING,
                description: 'One of "41" (purchase from an informal supplier, bills), "43" (minor expense, expenses) or "47" (payment abroad).',
                required: true,
            ),
            new ToolProperty(
                name: 'bill_id',
                type: PropertyType::INTEGER,
                description: 'The Kanvas bill id (from find_bill), for E41 or E47.',
                required: false,
            ),
            new ToolProperty(
                name: 'expense_id',
                type: PropertyType::INTEGER,
                description: 'The Kanvas expense id, for E43 or E47.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $ecf_type, ?int $bill_id = null, ?int $expense_id = null): array
    {
        return ApprovalOriginService::during(
            ApprovalOriginEnum::AGENT,
            fn (): array => $this->request($ecf_type, $bill_id, $expense_id)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $ecfType, ?int $billId, ?int $expenseId): array
    {
        $type = EcfTypeEnum::parse($ecfType);

        if ($type === null || ! $type->isPurchase()) {
            return ['requested' => false, 'reason' => 'invalid_type', 'message' => 'ecf_type must be 41, 43 or 47.'];
        }

        if (($billId === null) === ($expenseId === null)) {
            return ['requested' => false, 'reason' => 'one_document', 'message' => 'Pass exactly one of bill_id or expense_id.'];
        }

        $document = $billId !== null
            ? $this->resolveBillOrError($billId)
            : $this->resolveExpenseOrError((int) $expenseId);

        if (is_array($document)) {
            return ['requested' => false, ...$document];
        }

        try {
            $document = new SubmitPurchaseEcfForApprovalAction(
                document: $document,
                type: $type,
                user: $this->user,
            )->execute();
        } catch (Throwable $e) {
            return ['requested' => false, 'reason' => 'request_failed', 'message' => "The {$type->prefix()} was not requested: {$e->getMessage()}"];
        }

        $pending = $document->pendingApproval(EcfIssuanceService::PURCHASE_APPROVAL_TYPE) !== null;

        return [
            'requested' => true,
            'ecf_type' => $type->value,
            'pending_approval' => $pending,
            'encf' => EcfCompliance::number($document),
            'next' => $pending
                ? "The {$type->prefix()} waits for a person's approval and is issued when they sign. Say so; do not claim it was issued."
                : "No approval step applied, so the {$type->prefix()} was issued and sent to DGII.",
        ];
    }
}
