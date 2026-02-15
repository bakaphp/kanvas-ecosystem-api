<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Wallet\Wallet;
use Kanvas\Users\Models\Users;

class FlushWalletAction
{
    public function __construct(
        protected AppInterface $app,
        protected Users|Companies $holder,
        protected string $tag,
        protected UserInterface $admin,
    ) {
    }

    public function execute(): Wallet
    {
        $wallet = $this->holder->createAppWallet($this->app, ['name' => $this->tag]);
        $balance = (int) $wallet->balanceInt;

        if ($balance > 0) {
            $wallet->forceWithdraw($balance, [
                'description' => 'Admin wallet flush',
                'flushed_by' => $this->admin->getId(),
            ]);
        }

        return $wallet->refresh();
    }
}
