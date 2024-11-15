<?php

declare(strict_types=1);

namespace Kanvas\Wallet\Actions;

use Kanvas\Users\Models\Users;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Dashboard\Repositories\DashboardRepositories;
use Kanvas\Dashboard\Enums\DashboardEnum;
use Exception;

class RefundAction
{
    public function __construct(
        private Users $user,
        private Variants $item,
        private ?string $field = null,
        private ?string $value = null
    ) {
    }

    public function execute(): bool
    {
        //get the user wallet
        if (!$this->user->hasWallet('default')) {
            throw new Exception('User has no wallet');
        }

        if (!(bool)$this->user->paid($this->item)) {
            throw new Exception('Item has not been bought before');
        }

        //refund first in Stripe then if true in Stripe refund in wallet.
        return (bool)$this->user->refund($this->item);
    }
}
