<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet\Traits;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Wallet\Enums\ConfigurationEnum;
use Kanvas\Users\Models\Users;

trait HasWalletHolderTrait
{
    /**
     * Get the wallet holder based on app configuration.
     * Returns user if USE_USER_WALLET is enabled, otherwise returns company.
     */
    protected function getWalletHolder(AppInterface $app, UserInterface $user): Users|Companies
    {
        $useUserWallet = (bool) $app->get(ConfigurationEnum::USE_USER_WALLET->value);

        if ($useUserWallet) {
            return Users::getById($user->getId());
        }

        return Companies::getById($user->getCurrentCompany()->getId());
    }
}
