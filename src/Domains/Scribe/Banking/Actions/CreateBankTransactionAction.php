<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Banking\DataTransferObject\BankTransaction as BankTransactionData;
use Kanvas\Scribe\Banking\Models\BankTransaction;

/**
 * Lands one bank movement. Idempotent on (apps_id, source, external_id).
 *
 * A webhook and the safety-net poll will both deliver the same transaction — that's the design, not a bug.
 * Re-delivery refreshes the descriptive fields the bank may have amended after posting (memo, counterparty,
 * category settle from "pending" to final) but NEVER touches the accounting fields: match_status,
 * matched_to_*, journal_entry_id. Once a transaction is matched or posted, re-polling must not undo it.
 *
 * That split is the whole reason this isn't a plain upsert.
 */
class CreateBankTransactionAction
{
    public function __construct(
        public readonly BankTransactionData $data,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): BankTransaction
    {
        return DB::connection('accounting')->transaction(function (): BankTransaction {
            $existing = $this->findExisting();

            if ($existing !== null) {
                $this->refreshDescriptiveFields($existing);

                return $existing;
            }

            $transaction = new BankTransaction();
            $transaction->apps_id = $this->data->app->getId();
            $transaction->companies_id = $this->data->company->getId();
            $transaction->bank_account_id = $this->data->bankAccount->getId();
            $transaction->posted_at = $this->data->postedAt;
            $transaction->transaction_date = $this->data->transactionDate;
            $transaction->direction = $this->data->direction;
            $transaction->amount_native = $this->data->amountNative;
            $transaction->currency = $this->data->currency;
            $transaction->amount_base = $this->data->amountBase;
            $transaction->fx_rate_to_base = $this->data->fxRateToBase;
            $transaction->category = $this->data->category;
            $transaction->counterparty_name = $this->data->counterpartyName;
            $transaction->counterparty_account_last4 = $this->data->counterpartyAccountLast4;
            $transaction->memo = $this->data->memo;
            $transaction->raw_payload = $this->data->rawPayload;
            $transaction->source = $this->data->source;
            $transaction->external_id = $this->data->externalId;
            $transaction->metadata = $this->data->metadata;
            $transaction->users_id = $this->user?->getId();
            $transaction->save();

            $transaction->emitLedgerEvent('accounting.bank_transaction.created', payload: [
                'bank_account_id' => $transaction->bank_account_id,
                'direction' => $transaction->direction->value,
                'amount_native' => $transaction->amount_native,
                'currency' => $transaction->currency,
                'source' => $transaction->source,
            ]);

            return $transaction;
        });
    }

    private function findExisting(): ?BankTransaction
    {
        if ($this->data->externalId === null) {
            return null;
        }

        return BankTransaction::query()
            ->where('apps_id', $this->data->app->getId())
            ->where('source', $this->data->source)
            ->where('external_id', $this->data->externalId)
            ->first();
    }

    private function refreshDescriptiveFields(BankTransaction $transaction): void
    {
        $transaction->memo = $this->data->memo;
        $transaction->counterparty_name = $this->data->counterpartyName;
        $transaction->counterparty_account_last4 = $this->data->counterpartyAccountLast4;
        $transaction->raw_payload = $this->data->rawPayload;

        // A pending txn can settle to a different amount/date. Accepting the correction is right, but only
        // while nothing has been booked against it — once a JE exists, changing the amount would desync the
        // ledger from this row, and the fix is a reversal, not an overwrite.
        if (! $transaction->isAccountedFor()) {
            $transaction->posted_at = $this->data->postedAt;
            $transaction->transaction_date = $this->data->transactionDate;
            $transaction->amount_native = $this->data->amountNative;
            $transaction->amount_base = $this->data->amountBase;
            $transaction->fx_rate_to_base = $this->data->fxRateToBase;
            $transaction->category = $this->data->category;
        }

        $transaction->save();
    }
}
