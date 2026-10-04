<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Humano\Enums;

/**
 * Three static keys, no token exchange — their gateway authenticates every request
 * on headers alone, so there is nothing to cache and nothing to refresh.
 */
enum ConfigurationEnum: string
{
    case ENVIRONMENT = 'humano_environment';
    case SUBSCRIPTION_KEY = 'humano_subscription_key';
    case USER_KEY = 'humano_user_key';
    case MEDIATOR_CODE = 'humano_mediator_code';
}
