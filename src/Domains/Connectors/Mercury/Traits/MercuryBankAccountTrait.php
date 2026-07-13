<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Users\Models\Users;
use RuntimeException;

/**
 * The bank account already knows its app and company — passing them in alongside it only creates a way for
 * the three to disagree.
 */
trait MercuryBankAccountTrait
{
    /**
     * A bank account from another source has no Mercury id; calling `/account/{foreign-key}/…` would 404 — or
     * worse, hit a real Mercury account and file its transactions here.
     */
    protected function mercuryAccountId(): string
    {
        $externalId = $this->bankAccount->external_id;

        if ($externalId === null || $this->bankAccount->source !== 'mercury') {
            throw new RuntimeException(
                "BankAccount {$this->bankAccount->getId()} is not a Mercury account — refusing to pull it."
            );
        }

        return $externalId;
    }

    protected function app(): Apps
    {
        return $this->bankAccount->app;
    }

    protected function company(): Companies
    {
        return $this->bankAccount->company;
    }

    protected function owner(): Users
    {
        return Users::getById($this->bankAccount->users_id ?? 0);
    }

    /**
     * A card is money you OWE, so it backs a liability account rather than a cash one — which is also how we
     * tell the two apart, since we don't persist Mercury's `kind`.
     */
    protected function isCreditCard(): bool
    {
        return $this->bankAccount->glAccount?->account_sub_type === AccountSubTypeEnum::CREDIT_CARD_LIABILITY;
    }
}
