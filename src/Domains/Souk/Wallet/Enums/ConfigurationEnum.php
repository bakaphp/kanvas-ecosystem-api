<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet\Enums;

enum ConfigurationEnum: string
{
    case WALLET_DEFAULT_NAME = 'default';
    case PRODUCT_TYPE_WALLET_COIN_SLUG = 'wallet-coin';
    case PRODUCT_TYPE_WALLET_COIN_AMOUNT = 'wallet-coin-amount';
    case PRODUCT_TYPE_WALLET_COIN_CONSUME = 'wallet-coin-consume';
}
