<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Baka\Support\Str;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Bills\Actions\UpdateBillAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Bills\Models\BillLine;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\Subaccount;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/** Corrects the coding, number, dates or notes of a DRAFT AP bill; it never changes amounts or the vendor. */
#[AgentTool(name: 'Update AP Bill', category: 'accounting')]
class UpdateApBillTool extends Tool
{
    use HasKanvasContext;

    protected string $name = 'update_ap_bill';

    protected ?string $description = 'Corrects a DRAFT AP bill that is waiting for review — typically one an email '
        . 'invoice left in draft because its expense account could not be coded. Can set the expense account '
        . '(applied to every line), the subaccount, the invoice number, the invoice date, the due date and the '
        . 'notes. It never changes amounts or the vendor, and it only works on a draft: a bill already sent for '
        . 'approval is not editable. It does not send the bill for approval — call submit_ap_bill_for_approval '
        . 'for that. Only call it when the person tells you what to change.';

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
                description: 'The Kanvas bill id (bill_id from create_ap_bill, find_bill or list_open_bills).',
                required: true,
            ),
            new ToolProperty(
                name: 'gl_account_number',
                type: PropertyType::STRING,
                description: 'Expense account number to put on every line (list_gl_accounts shows the chart).',
                required: false,
            ),
            new ToolProperty(
                name: 'subaccount',
                type: PropertyType::STRING,
                description: 'Subaccount code to put on every line.',
                required: false,
            ),
            new ToolProperty(
                name: 'invoice_number',
                type: PropertyType::STRING,
                description: 'The vendor invoice number, exactly as printed on the invoice.',
                required: false,
            ),
            new ToolProperty(
                name: 'invoice_date',
                type: PropertyType::STRING,
                description: 'Invoice date, YYYY-MM-DD.',
                required: false,
            ),
            new ToolProperty(
                name: 'due_date',
                type: PropertyType::STRING,
                description: 'Due date, YYYY-MM-DD.',
                required: false,
            ),
            new ToolProperty(
                name: 'notes',
                type: PropertyType::STRING,
                description: 'Replaces the bill notes.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $bill_id,
        ?string $gl_account_number = null,
        ?string $subaccount = null,
        ?string $invoice_number = null,
        ?string $invoice_date = null,
        ?string $due_date = null,
        ?string $notes = null,
    ): array {
        try {
            $bill = Bill::getByIdFromCompanyApp($bill_id, $this->company, $this->app);
        } catch (ExceptionsModelNotFoundException) {
            return [
                'updated' => false,
                'reason' => 'bill_not_found',
                'message' => "No bill with id {$bill_id} for this app/company.",
            ];
        }

        if ($bill->document_status !== BillDocumentStatusEnum::DRAFT) {
            return [
                'updated' => false,
                'bill_id' => $bill->getId(),
                'reason' => 'not_a_draft',
                'document_status' => $bill->document_status->value,
                'message' => "Bill {$bill_id} is {$bill->document_status->value}; only a draft can be edited. "
                    . 'Do not work around it: tell the person.',
            ];
        }

        $changes = [];

        $glAccountNumber = Str::trimToNull($gl_account_number);
        if ($glAccountNumber !== null) {
            $account = Account::activeByNumber($this->app->getId(), $this->company->getId(), $glAccountNumber);

            if ($account === null) {
                return [
                    'updated' => false,
                    'reason' => 'account_not_found',
                    'message' => "No active GL account with number \"{$glAccountNumber}\" for this app/company.",
                ];
            }

            $changes['expense_account_id'] = $account->getId();
        }

        $subaccountCode = Str::trimToNull($subaccount);
        if ($subaccountCode !== null) {
            $changes['subaccount_id'] = (int) Subaccount::resolveByCode(
                $this->app,
                $this->company,
                $subaccountCode,
            )->getKey();
        }

        foreach (['bill_number' => $invoice_number, 'notes' => $notes] as $field => $value) {
            $text = Str::trimToNull($value);
            if ($text !== null) {
                $changes[$field] = $text;
            }
        }

        foreach (['bill_date' => $invoice_date, 'due_date' => $due_date] as $field => $value) {
            $text = Str::trimToNull($value);
            if ($text === null) {
                continue;
            }

            $date = $this->strictDate($text);
            if ($date === null) {
                return [
                    'updated' => false,
                    'reason' => 'invalid_date',
                    'message' => "\"{$text}\" is not a valid date; use YYYY-MM-DD.",
                ];
            }

            $changes[$field] = $date;
        }

        if ($changes === []) {
            return [
                'updated' => false,
                'reason' => 'nothing_to_change',
                'message' => 'Pass at least one of gl_account_number, subaccount, invoice_number, invoice_date, '
                    . 'due_date or notes.',
            ];
        }

        try {
            $updated = new UpdateBillAction(
                bill: $bill,
                data: BillData::forUpdate($bill, $this->app, $this->company, $changes),
                user: $this->user,
            )->execute();
        } catch (UniqueConstraintViolationException) {
            return [
                'updated' => false,
                'bill_id' => $bill->getId(),
                'reason' => 'duplicate_invoice',
                'message' => "Invoice number {$changes['bill_number']} is already recorded for this vendor. "
                    . 'Nothing was changed.',
            ];
        } catch (Throwable $e) {
            return [
                'updated' => false,
                'bill_id' => $bill->getId(),
                'reason' => 'update_failed',
                'message' => 'Could not update the bill: ' . $e->getMessage(),
            ];
        }

        return [
            'updated' => true,
            'bill_id' => $updated->getId(),
            'bill_number' => $updated->bill_number,
            'document_status' => $updated->document_status->value,
            'amount' => (float) $updated->total_native,
            'currency' => $updated->currency,
            'bill_date' => $updated->bill_date?->toDateString(),
            'due_date' => $updated->due_date?->toDateString(),
            'gl_accounts' => $updated->lines
                ->map(fn (BillLine $line): string => (string) $line->expenseAccount?->account_number)
                ->unique()
                ->values()
                ->all(),
            'changed' => array_keys($changes),
            'next' => 'The draft was corrected and is still a draft: it has NOT been sent for approval. '
                . 'Call submit_ap_bill_for_approval when the person says to send it.',
        ];
    }

    /** `Carbon::parse` would roll 2026-02-31 over to March, so the parsed date must print back as typed. */
    private function strictDate(string $value): ?string
    {
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        return $date !== false && $date->format('Y-m-d') === $value ? $date->toDateString() : null;
    }
}
