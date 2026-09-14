<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\FilesExpenseForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\RequiresHumanCaller;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesFilesystemForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\Expenses\Actions\CreateExpenseAction;
use Kanvas\Scribe\Expenses\Actions\SubmitExpenseForApprovalAction;
use Kanvas\Scribe\Expenses\DataTransferObject\Expense as ExpenseData;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Files an expense the CALLER paid out of pocket, and submits it for approval in the same call.
 *
 * `paid_by` is fixed to EMPLOYEE_PERSONAL and `paid_by_users_id` comes from the acting user — neither
 * is a parameter. That is the point of the tool rather than a convenience: it is what creates the
 * Due to Employees liability, and it is what stops a model from filing someone else's expense or
 * quietly booking a personal outlay as company-paid (which would leave the employee owed money with
 * nothing on the books saying so). A charge the company itself paid is the sibling tool,
 * record_company_card_expense — a different credit, so a different tool rather than an argument.
 */
#[AgentTool(name: 'Submit My Expense', category: 'accounting')]
class SubmitMyExpenseTool extends Tool implements HasRunKey
{
    use FilesExpenseForTool;
    use HasKanvasContext;
    use ReportsToolOutcome;
    use RequiresHumanCaller;
    use ResolvesFilesystemForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'submit_my_expense',
            description: 'Files an expense YOU paid out of pocket and submits it for approval — a client dinner, a '
                . 'taxi, a hotel, anything you covered personally and want reimbursed. Always files it as paid by '
                . 'the person you are talking to, so never call it on behalf of someone else, and not for a '
                . 'charge the company already paid on its own card — that is record_company_card_expense. Read '
                . 'the receipt with extract_expense_receipt first when there is one, and confirm the amount with '
                . 'the person before filing.',
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
                name: 'amount',
                type: PropertyType::NUMBER,
                description: 'Total paid, in `currency`, including tax. Must be greater than zero.',
                required: true,
            ),
            new ToolProperty(
                name: 'description',
                type: PropertyType::STRING,
                description: 'What it was for, in the employee\'s own words — e.g. "Dinner with ACME while '
                    . 'closing the renewal". Include who was there for a client meal.',
                required: true,
            ),
            new ToolProperty(
                name: 'expense_date',
                type: PropertyType::STRING,
                description: 'ISO date (YYYY-MM-DD) the money was spent. Defaults to today when genuinely unknown '
                    . '— prefer the date on the receipt.',
                required: false,
            ),
            new ToolProperty(
                name: 'merchant',
                type: PropertyType::STRING,
                description: 'Where it was spent (the restaurant, hotel, airline).',
                required: false,
            ),
            new ToolProperty(
                name: 'tax_amount',
                type: PropertyType::NUMBER,
                description: 'Tax included in `amount`, when the receipt breaks it out. Defaults to 0.',
                required: false,
            ),
            new ToolProperty(
                name: 'currency',
                type: PropertyType::STRING,
                description: 'ISO currency of `amount`. Defaults to USD.',
                required: false,
            ),
            new ToolProperty(
                name: 'filesystem_id',
                type: PropertyType::INTEGER,
                description: 'The uploaded receipt, when there is one — it gets attached to the expense.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        float $amount,
        string $description,
        ?string $expense_date = null,
        ?string $merchant = null,
        ?float $tax_amount = null,
        ?string $currency = null,
        ?int $filesystem_id = null,
    ): array {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('expense');
        }

        $user = $this->humanCallerOrDenial('file an expense', 'Say plainly that NOTHING was filed.');

        if (is_array($user)) {
            return $user;
        }

        $refusal = $this->nonPositiveAmountRefusal($amount);

        if ($refusal !== null) {
            return $refusal;
        }

        $expenseAccountId = $this->expenseAccountOrDenial();

        if (is_array($expenseAccountId)) {
            return $expenseAccountId;
        }

        $tax = $tax_amount ?? 0.0;

        try {
            $expense = new CreateExpenseAction(
                data: new ExpenseData(
                    app: $this->app,
                    company: $this->company,
                    lines: $this->singleExpenseLine(
                        $description,
                        $amount,
                        $tax,
                        $expenseAccountId,
                    ),
                    expense_date: $expense_date !== null ? Carbon::parse($expense_date) : Carbon::today(),
                    currency: $currency ?? 'USD',
                    fx_rate_to_base: 1.0,
                    vendor_display_name: Str::trimToNull($merchant),
                    paid_by: ExpensePaidByEnum::EMPLOYEE_PERSONAL,
                    paid_by_users_id: $user->getId(),
                    notes: $description,
                    // `source` stays the default 'kanvas' — it is a DB enum of external systems of
                    // record, and an agent-filed expense originates here. Provenance goes in metadata.
                    metadata: ['submitted_via' => 'agent'],
                ),
                user: $user,
            )->execute();
        } catch (Throwable $e) {
            report($e);

            return $this->failed(
                'I could not file that expense: ' . $e->getMessage(),
                ['status' => 'not_created'],
                guidance: 'Say plainly that the expense was NOT filed.',
            );
        }

        $attachmentWarning = $this->attachExpenseReceipt($expense, $filesystem_id, $user);

        $submitted = new SubmitExpenseForApprovalAction(
            expense: $expense,
            user: $user,
        )->execute();

        return $this->ok(
            array_filter([
                'status' => 'submitted',
                'expense_id' => $submitted->getId(),
                'expense_number' => $submitted->expense_number,
                'total' => (float) $submitted->total_native,
                'currency' => $submitted->currency,
                'expense_status' => $submitted->status->value,
                'reimbursement_status' => $submitted->reimbursement_status->value,
                'attachment_warning' => $attachmentWarning,
                'message' => 'Filed as paid by you and sent for approval. It shows up in what the company owes '
                    . 'you once a manager approves it.',
            ], fn (mixed $value): bool => $value !== null),
        );
    }
}
