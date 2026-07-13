<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\Mercury\DataTransferObject\MercuryAccount;
use Kanvas\Connectors\Mercury\DataTransferObject\MercuryTransaction;
use Kanvas\Connectors\Mercury\Services\MercuryCategoryResolverService;
use Kanvas\Connectors\Mercury\Traits\MercuryBankAccountTrait;
use Kanvas\Scribe\Banking\Actions\CreateBankTransactionAction;
use Kanvas\Scribe\Banking\DataTransferObject\BankTransaction as BankTransactionData;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Models\BankTransaction;

/**
 * The single place the nightly pull and the webhook both map a transaction. Mapping it twice would let them
 * drift — the same charge landing with a different category depending on which path got there first.
 */
class LandMercuryTransactionAction
{
    use MercuryBankAccountTrait;

    public function __construct(
        public readonly BankAccount $bankAccount,
        public readonly MercuryTransaction $transaction,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): BankTransaction
    {
        return new CreateBankTransactionAction(
            data: new BankTransactionData(
                app: $this->app(),
                company: $this->company(),
                bankAccount: $this->bankAccount,
                postedAt: $this->transaction->postedAt,
                transactionDate: $this->transaction->postedAt->copy()->startOfDay(),
                direction: $this->transaction->direction,
                amountNative: $this->transaction->amount,
                currency: MercuryAccount::CURRENCY,
                // Mercury is USD-only, and USD is the base currency for the tenants on it. A non-USD-base
                // tenant would need an FxRates lookup here.
                amountBase: $this->transaction->amount,
                fxRateToBase: 1.0,
                category: new MercuryCategoryResolverService($this->app(), $this->company())
                    ->resolve($this->transaction),
                counterpartyName: $this->transaction->counterpartyName,
                memo: $this->transaction->memo,
                rawPayload: $this->transaction->raw,
                source: 'mercury',
                externalId: $this->transaction->id,
            ),
            user: $this->user,
        )->execute();
    }
}
