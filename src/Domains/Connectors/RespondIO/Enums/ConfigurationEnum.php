<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Enums;

enum ConfigurationEnum: string
{
    case BEARER_TOKEN = 'RESPONDIO_BEAR_TOKEN_AUTH';
    case ENABLED = 'respondio_enabled';

    // The WhatsApp number the workspace's channel uses. Respond.io never names it in a webhook, so
    // the tenant states it: it is what an agent's own WhatsApp number is matched against.
    case PHONE_NUMBER = 'respondio_phone_number';
    case WEBHOOK_SIGNING_KEY = 'respondio_webhook_signing_key';
}
