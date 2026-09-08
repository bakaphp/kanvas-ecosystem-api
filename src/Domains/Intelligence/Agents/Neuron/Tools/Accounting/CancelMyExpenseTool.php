<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Enums\ToolOutcomeEnum;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesExpenseForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\Expenses\Actions\VoidExpenseAction;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Self-service: withdraw an expense the caller filed by mistake, before anyone has approved it.
 *
 * Two gates, both deliberate. It must be the CALLER's own expense — tenant scoping alone would let
 * one employee void a colleague's by guessing an id. And it must not yet be APPROVED: voiding an
 * approved expense posts a reversal JE against the books, which is a finance action, not something
 * an employee does by chatting.
 */
#[AgentTool(name: 'Cancel My Expense', category: 'accounting')]
class CancelMyExpenseTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use ReportsToolOutcome;
    use ResolvesExpenseForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'cancel_my_expense',
            description: 'Withdraws an expense YOU filed that has not been approved yet — for one submitted by '
                . 'mistake, twice, or with the wrong amount. Use list_my_expenses first to get the expense_id. '
                . 'It cannot touch an expense that is already approved, or one filed by someone else.',
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
                description: 'The expense to withdraw, from list_my_expenses.',
                required: true,
            ),
            new ToolProperty(
                name: 'reason',
                type: PropertyType::STRING,
                description: 'Why it is being withdrawn, in a few words (e.g. "duplicate", "wrong amount").',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $expense_id, ?string $reason = null): array
    {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('expense');
        }

        $user = $this->contextUser();

        if ($user === null) {
            return $this->denied(
                'I cannot tell who you are on this surface, so I cannot withdraw an expense for you.',
                ['status' => 'no_user_context'],
                guidance: 'Say plainly that NOTHING was changed.',
            );
        }

        $result = $this->resolveMyExpenseOrError($expense_id, $user->getId());

        if (is_array($result)) {
            return $this->denied(
                (string) $result['message'],
                ['status' => 'not_yours_or_missing'],
                guidance: 'Say plainly that NOTHING was withdrawn.',
            );
        }

        if ($result->status === ExpenseStatusEnum::APPROVED) {
            return $this->denied(
                "Expense {$expense_id} is already approved, so withdrawing it would reverse an entry already on "
                . 'the books.',
                ['status' => 'already_approved'],
                guidance: 'Say it was NOT withdrawn and that Finance has to reverse an approved expense.',
            );
        }

        if ($result->status->isTerminal()) {
            return $this->noop(
                [
                    'status' => 'already_closed',
                    'expense_status' => $result->status->value,
                ],
                guidance: 'It is already closed, so there was nothing to withdraw. Do not call this again.',
            );
        }

        try {
            $voided = new VoidExpenseAction(
                expense: $result,
                voidReasonCode: trim((string) $reason) ?: 'withdrawn_by_employee',
                user: $user,
            )->execute();
        } catch (Throwable $e) {
            report($e);

            return $this->withOutcome(
                ToolOutcomeEnum::PROVIDER_ERROR,
                [
                    'success' => false,
                    'status' => 'not_withdrawn',
                    'error' => 'I could not withdraw that expense: ' . $e->getMessage(),
                ],
                guidance: 'Say plainly that it was NOT withdrawn.',
            );
        }

        return $this->ok([
            'status' => 'withdrawn',
            'expense_id' => $voided->getId(),
            'expense_number' => $voided->expense_number,
            'expense_status' => $voided->status->value,
        ]);
    }
}
