<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Models\BankTransaction;
use Kanvas\Scribe\Bills\Models\Bill;

/**
 * Bank account, bank transaction and bill lookups for the statement, suspense and payment tools.
 * Every id comes from the model, so each lookup is pinned to the tool's own app and company and a
 * miss comes back as a payload the tool returns verbatim.
 *
 * Requires HasKanvasContext and ReportsToolOutcome on the same tool.
 */
trait ResolvesBankingRecordsForTool
{
    /**
     * @return BankAccount|array<string, mixed>
     */
    protected function resolveBankAccountOrError(int $bankAccountId): BankAccount|array
    {
        return BankAccount::query()
            ->where('id', $bankAccountId)
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', false)
            ->first() ?? $this->bankingMiss('bank_account_not_found', "No bank account with id {$bankAccountId} for this company. Call bank_statement_coverage to see the accounts.");
    }

    /**
     * @return BankTransaction|array<string, mixed>
     */
    protected function resolveBankTransactionOrError(int $bankTransactionId): BankTransaction|array
    {
        return BankTransaction::query()
            ->where('id', $bankTransactionId)
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', false)
            ->first() ?? $this->bankingMiss('bank_transaction_not_found', "No bank transaction with id {$bankTransactionId} for this company. Call list_suspense for the ids.");
    }

    /**
     * @return Bill|array<string, mixed>
     */
    protected function resolveBillOrError(int $billId): Bill|array
    {
        return Bill::query()
            ->where('id', $billId)
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', false)
            ->first() ?? $this->bankingMiss('bill_not_found', "No bill with id {$billId} for this company. Use find_bill or list_open_bills to get the id.");
    }

    /**
     * @return array<string, mixed>
     */
    private function bankingMiss(string $reason, string $message): array
    {
        return $this->notFound(['success' => false, 'reason' => $reason, 'message' => $message]);
    }
}
