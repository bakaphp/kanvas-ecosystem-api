<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet\Enums;

enum ConfigurationEnum: string
{
    case WALLET_DEFAULT_NAME = 'default';
    case WALLET_PROMOTIONAL_NAME = 'promotional';
    case WALLET_SUBSCRIPTION_NAME = 'subscription';

    case PRODUCT_TYPE_WALLET_COIN_SLUG = 'wallet-coin';
    case PRODUCT_TYPE_USER_WALLET_COIN_SLUG = 'user-wallet-coin';
    case PRODUCT_TYPE_WALLET_COIN_AMOUNT = 'wallet-coin-amount';
    case PRODUCT_TYPE_WALLET_COIN_CONSUME = 'wallet-coin-consume';
    case USE_USER_WALLET = 'app_use_user_wallet';

    case PRODUCT_TYPE_WALLET_PROMOTIONAL_SLUG = 'wallet-promotional';
    case PRODUCT_TYPE_WALLET_PROMOTIONAL_AMOUNT = 'wallet-promotional-amount';

    case PRODUCT_TYPE_WALLET_SUBSCRIPTION_SLUG = 'wallet-subscription';
    case PRODUCT_TYPE_WALLET_SUBSCRIPTION_AMOUNT = 'wallet-subscription-amount';
}
