<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Scribe\Expenses\Actions\AttachExpenseReceiptAction;
use Kanvas\Scribe\Expenses\DataTransferObject\ExpenseLine as ExpenseLineData;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\PdfIngest\Traits\ExtractsPdfPayloadValuesTrait;
use Spatie\LaravelData\DataCollection;
use Throwable;

/**
 * Everything a "file an expense from what someone said in chat" tool needs EXCEPT the paid_by
 * decision, so that decision stays the only thing separating one such tool from the next. Splitting
 * these out is what keeps a second paid_by lane from arriving as a second copy of the first, which
 * is how the refusal texts and the tax-split drift apart.
 *
 * Requires HasKanvasContext (`$this->app` / `$this->company`), ReportsToolOutcome and
 * ResolvesFilesystemForTool on the host.
 */
trait FilesExpenseForTool
{
    use ExtractsPdfPayloadValuesTrait;

    /**
     * The finished refusal to return verbatim, or null when the amount is usable.
     *
     * @return array<string, mixed>|null
     */
    protected function nonPositiveAmountRefusal(float $amount): ?array
    {
        if ($amount > 0) {
            return null;
        }

        return $this->invalidArgs(
            'An expense needs an amount greater than zero.',
            ['status' => 'invalid_amount'],
            guidance: 'Ask the person for the amount before calling again.',
        );
    }

    /**
     * The account the line lands on, or the finished refusal when the tenant's chart of accounts has
     * nothing to book against.
     *
     * @return int|array<string, mixed>
     */
    protected function expenseAccountOrDenial(): int|array
    {
        return $this->resolveDefaultExpenseAccountId() ?? $this->denied(
            'This company\'s chart of accounts has no expense account to book this against.',
            ['status' => 'no_expense_account'],
            guidance: 'Say the expense was NOT filed and that Finance needs to set up an expense account.',
        );
    }

    /**
     * A receipt is one number, and that number is what the person paid — tax included. So the tax
     * comes back OUT of the total rather than being added on top of it, or the expense books high by
     * the tax on every single call.
     *
     * @return DataCollection<ExpenseLineData>
     */
    protected function singleExpenseLine(
        string $description,
        float $amount,
        float $tax,
        int $expenseAccountId,
    ): DataCollection {
        return new DataCollection(ExpenseLineData::class, [
            new ExpenseLineData(
                description: $description,
                amount_native: $amount - $tax,
                expense_account_id: $expenseAccountId,
                tax_amount_native: $tax,
            ),
        ]);
    }

    /**
     * A receipt that fails to attach must not sink an otherwise valid expense — the amount is already
     * on the books and the file can be added later, so this reports rather than throws.
     */
    protected function attachExpenseReceipt(Expense $expense, ?int $filesystemId, UserInterface $user): ?string
    {
        if ($filesystemId === null) {
            return null;
        }

        $receipt = $this->findTenantFile($filesystemId);

        if ($receipt === null) {
            return "No file with filesystem_id {$filesystemId} for this company — the expense was filed without it.";
        }

        try {
            new AttachExpenseReceiptAction(
                expense: $expense,
                filesystem: $receipt,
                user: $user,
                metadata: ['attached_via' => 'agent'],
            )->execute();
        } catch (Throwable $e) {
            return 'The expense was filed but the receipt could not be attached: ' . $e->getMessage();
        }

        return null;
    }
}
