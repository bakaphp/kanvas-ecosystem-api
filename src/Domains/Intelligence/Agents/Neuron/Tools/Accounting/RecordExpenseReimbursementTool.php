<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Enums\ToolOutcomeEnum;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesExpenseForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\Expenses\Actions\RecordExpenseReimbursementAction;
use Kanvas\Scribe\Expenses\Enums\ExpenseReimbursementStatusEnum;
use Kanvas\Scribe\Expenses\Exceptions\InvalidExpenseTransitionException;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Records that an employee has actually been paid back, clearing the Due to Employees liability with
 * a real JE (DR Due to Employees / CR Cash).
 *
 * This asserts money left the bank. It does not move any money itself — call it AFTER the transfer,
 * never to promise one. Same posture as apply_ap_payment: only on an explicit human instruction.
 */
#[AgentTool(name: 'Record Expense Reimbursement', category: 'accounting')]
class RecordExpenseReimbursementTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use ReportsToolOutcome;
    use ResolvesExpenseForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'record_expense_reimbursement',
            description: 'Records that an employee has been paid back for an expense they covered personally, '
                . 'clearing the liability and posting the cash journal entry. It does NOT send any money — it '
                . 'records a transfer that has already happened. Only call it when someone explicitly tells you '
                . 'the reimbursement was paid; never on your own initiative, and never to promise a payment.',
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
                name: 'expense_id',
                type: PropertyType::INTEGER,
                description: 'The expense that was reimbursed, from query_due_to_employees.',
                required: true,
            ),
            new ToolProperty(
                name: 'payment_id',
                type: PropertyType::INTEGER,
                description: 'The Scribe payment record for the transfer, when there is one.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $expense_id, ?int $payment_id = null): array
    {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('reimbursement');
        }

        $result = $this->resolveExpenseOrError($expense_id);

        if (is_array($result)) {
            return $this->denied(
                (string) $result['message'],
                ['status' => 'not_found'],
                guidance: 'Say plainly that NOTHING was recorded.',
            );
        }

        if ($result->reimbursement_status === ExpenseReimbursementStatusEnum::PAID) {
            return $this->noop(
                [
                    'status' => 'already_reimbursed',
                    'expense_id' => $expense_id,
                    'reimbursed_at' => $result->reimbursed_at?->toDateString(),
                ],
                guidance: 'It was already paid back, so nothing changed. Do not call this again for it.',
            );
        }

        try {
            $reimbursed = new RecordExpenseReimbursementAction(
                expense: $result,
                user: $this->user,
                reimbursementPaymentId: $payment_id,
            )->execute();
        } catch (InvalidExpenseTransitionException $e) {
            // Expected: the expense (or its reimbursement) has not been approved yet. A business
            // state, not a fault — explain it rather than reporting it.
            return $this->denied(
                $e->getMessage(),
                ['status' => 'not_ready'],
                guidance: 'Say plainly that NOTHING was recorded, and that the expense has to be approved first.',
            );
        } catch (Throwable $e) {
            report($e);

            return $this->withOutcome(
                ToolOutcomeEnum::PROVIDER_ERROR,
                [
                    'success' => false,
                    'status' => 'not_recorded',
                    'error' => 'I could not record that reimbursement: ' . $e->getMessage(),
                ],
                guidance: 'Say plainly that NOTHING was recorded.',
            );
        }

        return $this->ok([
            'status' => 'reimbursed',
            'expense_id' => $reimbursed->getId(),
            'expense_number' => $reimbursed->expense_number,
            'total' => (float) $reimbursed->total_native,
            'currency' => $reimbursed->currency,
            'reimbursement_status' => $reimbursed->reimbursement_status->value,
            'reimbursed_at' => $reimbursed->reimbursed_at?->toDateString(),
        ]);
    }
}
