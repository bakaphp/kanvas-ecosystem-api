<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet\Actions;

use Bavix\Wallet\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Override;

class AddFundsToUserWalletAction extends AddFundsToWalletActionBase
{
    #[Override]
    public function execute(): Transaction
    {
        return $this->processTransaction();
    }

    #[Override]
    protected function getWalletHolder(): Model
    {
        return $this->order->user;
    }
}
