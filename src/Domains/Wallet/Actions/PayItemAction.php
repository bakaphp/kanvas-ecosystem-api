<?php

declare(strict_types=1);

namespace Kanvas\Wallet\Actions;

use Kanvas\Users\Models\Users;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Dashboard\Repositories\DashboardRepositories;
use Kanvas\Dashboard\Enums\DashboardEnum;
use Exception;

class PayItemAction
{
    public function __construct(
        private Users $user,
        private Variants $item,
        private ?string $field = null,
        private ?string $value = null
    ) {
    }

    public function execute(): void
    {
        //get the user wallet
        if (!$this->user->hasWallet('default')) {
            throw new Exception('User has no wallet');
        }

        //We need to check if the user has enough to buy the product, if he does then we try to purchase the product in Stripe.

        $this->user->pay($this->item); //we need to check here if the item in Stripe has been bought first and if the user

        if (!(bool)$this->user->paid($this->item)) {
            throw new Exception('Item could not be bought');
        }
    }
}
