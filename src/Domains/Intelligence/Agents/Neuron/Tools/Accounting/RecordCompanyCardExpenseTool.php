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
 * Files a charge the COMPANY already paid on its own card, and submits it for approval.
 *
 * The counterpart to submit_my_expense, and the reason both exist rather than one tool with a
 * paid_by argument: the two book opposite things. A personal outlay credits Due to Employees and
 * leaves the company owing the employee; a card swipe credits Credit Card Liability and owes nobody
 * anything. Handing that choice to a model as a parameter means one bad guess quietly invents — or
 * quietly erases — a debt to a person. Fixing paid_by per tool makes the choice the caller's, made
 * in words, before the tool is picked.
 *
 * `paid_by_users_id` stays null, matching what PDF ingest writes for a card receipt. The column is
 * what the Due-to-Employees report pays out against, so a name in it on a company-paid row is a
 * claim waiting to be honoured twice. Who filed this is already on `users_id`, which is the question
 * anyone actually asks of a card charge.
 *
 * Approval is not ceremony here: the bank-feed reconciler only counts APPROVED expenses when it
 * looks for a charge the books already hold (BankTransactionMatchService::findBookedExpense), so a
 * card expense that never gets approved is one the Mercury feed will happily book a second time.
 */
#[AgentTool(name: 'Record Company Card Expense', category: 'accounting')]
class RecordCompanyCardExpenseTool extends Tool implements HasRunKey
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
            name: 'record_company_card_expense',
            description: 'Files a purchase paid on a COMPANY card — a card the business issued, where the money '
                . 'has already left the company and nobody is owed anything back. Not for money somebody paid '
                . 'out of their own pocket and wants back — that is a reimbursement claim, a different entry on '
                . 'the books — so ask whose card it was when the receipt does not say. Read the receipt with '
                . 'extract_expense_receipt first when there is one.',
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
                description: 'Total charged to the card, in `currency`, including tax. Must be greater than zero.',
                required: true,
            ),
            new ToolProperty(
                name: 'description',
                type: PropertyType::STRING,
                description: 'What the company bought and what for — e.g. "Figma seats for the design team". '
                    . 'Include who it was for on a client expense.',
                required: true,
            ),
            new ToolProperty(
                name: 'expense_date',
                type: PropertyType::STRING,
                description: 'ISO date (YYYY-MM-DD) of the charge. Defaults to today when genuinely unknown — '
                    . 'prefer the date on the receipt, since that is what the card statement will show.',
                required: false,
            ),
            new ToolProperty(
                name: 'merchant',
                type: PropertyType::STRING,
                description: 'Where it was spent (the store, airline, software vendor).',
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
                name: 'card_last4',
                type: PropertyType::STRING,
                description: 'The last 4 digits of the card, when the receipt or the person gives them. Worth '
                    . 'passing whenever you have it — it is what tells Finance which card on the statement this '
                    . 'belongs to when several are in use.',
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
        ?string $card_last4 = null,
        ?int $filesystem_id = null,
    ): array {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('expense');
        }

        // Not the Due-to-Employees argument that guards submit_my_expense — nothing is owed here.
        // This one posts a real credit against the company's card on approval, and an agent turn is
        // steered by whatever it last read: an inbound invoice email, a scraped page. The person in
        // the conversation is the assertion that the charge is real, and lands on `users_id` as the
        // only audit trail the row will ever have.
        $user = $this->humanCallerOrDenial('record a company-card expense', 'Say plainly that NOTHING was filed.');

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
        $last4 = $this->normalizeCardLast4($card_last4);

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
                    paid_by: ExpensePaidByEnum::COMPANY_CARD,
                    vendor_display_name: Str::trimToNull($merchant),
                    notes: $description,
                    metadata: array_filter([
                        'submitted_via' => 'agent',
                        'card_last4' => $last4,
                    ], fn (mixed $value): bool => $value !== null),
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
                'paid_by' => $submitted->paid_by->value,
                'card_last4' => $last4,
                'attachment_warning' => $attachmentWarning,
                'message' => 'Filed as paid on the company card and sent for approval. Nobody is owed anything '
                    . 'for it — the company has already paid.',
            ], fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * Models hand this over however the receipt printed it — "****0768", "xxxx-0768", "Visa 0768".
     * Keeping the digits is the whole value of the field, and a mangled one silently fails to line up
     * against the statement later.
     */
    private function normalizeCardLast4(?string $cardLast4): ?string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $cardLast4);

        return strlen($digits) < 4 ? null : substr($digits, -4);
    }
}
