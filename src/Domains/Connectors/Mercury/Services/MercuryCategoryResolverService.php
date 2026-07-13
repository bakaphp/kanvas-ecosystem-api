<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Mercury\DataTransferObject\MercuryTransaction;
use Kanvas\Scribe\Banking\Enums\BankTransactionCategoryEnum;
use Kanvas\Scribe\Banking\Models\BankAccount;

/**
 * Decides what a Mercury transaction IS, for both the poll and the webhook.
 *
 * Shared deliberately: if the two paths classified independently, the same charge could land as UNKNOWN when
 * polled and TRANSFER when webhooked, and which one you got would depend on timing.
 *
 * Money moving between accounts WE OWN is a transfer, never a spend. Mercury's `kind` catches checking↔savings
 * (`internalTransfer`) but NOT a credit-card payment, which it reports as the useless `kind: "other"` with the
 * card's own name as the counterparty. Left UNKNOWN that looks like a payment to an outside vendor, when in
 * fact no money left the business — it moved from cash to the card liability.
 */
class MercuryCategoryResolverService
{
    public function __construct(
        protected readonly AppInterface $app,
        protected readonly CompanyInterface $company,
    ) {
    }

    public function resolve(MercuryTransaction $transaction): BankTransactionCategoryEnum
    {
        if ($transaction->category !== BankTransactionCategoryEnum::UNKNOWN) {
            return $transaction->category;
        }

        $counterparty = $transaction->counterpartyName;

        return $counterparty !== null && $this->isOwnAccountName($counterparty)
            ? BankTransactionCategoryEnum::TRANSFER
            : $transaction->category;
    }

    private function isOwnAccountName(string $counterparty): bool
    {
        $ownNames = BankAccount::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->where('source', 'mercury')
            ->pluck('account_name')
            ->map(fn (string $name): string => strtolower(trim($name)))
            ->all();

        return in_array(strtolower(trim($counterparty)), $ownNames, true);
    }
}
