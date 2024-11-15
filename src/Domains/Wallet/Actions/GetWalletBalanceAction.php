<?php

declare(strict_types=1);

namespace Kanvas\Wallet\Actions;

use Kanvas\Users\Models\Users;
use Exception;

class GetWalletBalanceAction
{
    private const DEFFAULT_WALLET_NAME = 'default';

    public function __construct(
        private Users $user
    ) {
    }

    public function execute(): int
    {
        if (!$this->user->hasWallet(self::DEFFAULT_WALLET_NAME)) {
            throw new Exception('User has no wallet');
        }
        
        return (int)$this->user->balance;
    }
}
