<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;
use RuntimeException;

/**
 * For actions that operate on ONE Mercury-backed BankAccount.
 *
 * The bank account already knows which app and company it belongs to, so passing them in alongside it only
 * creates a way for the three to disagree.
 */
trait MercuryBankAccountTrait
{
    /**
     * The account's id on Mercury's side.
     *
     * Guards the assumption every caller makes. A bank account from another source has no Mercury id, and
     * calling `/account/{whatever}/…` with a foreign key would either 404 or — worse — hit a real Mercury
     * account belonging to someone else's row and file its transactions here.
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
}
