<?php

declare(strict_types=1);

namespace Kanvas\Wallet\Actions;

use Kanvas\Users\Models\Users;
use Kanvas\Wallet\Models\WalletsTransactions;
use Exception;
use Illuminate\Database\Eloquent\Collection;

class GetWalletBalanceHistoryAction
{
    private const DEFFAULT_WALLET_NAME = 'default';

    public function __construct(
        private Users $user
    ) {
    }

    public function execute(): Collection
    {
        if (!$this->user->hasWallet(self::DEFFAULT_WALLET_NAME)) {
            throw new Exception('User has no wallet');
        }

        //Later we need to add search by apps_id too
        return WalletsTransactions::select('type','amount','after_transaction_balance','confirmed','meta','created_at')
            ->where('payable_id', $this->user->getId())
            ->orderBy('created_at','desc')
            ->get();
    }
}
