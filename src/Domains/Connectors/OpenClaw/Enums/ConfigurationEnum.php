<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Enums;

enum ConfigurationEnum: string
{
    case SSH_HOST = 'openclaw_ssh_host';
    case SSH_PORT = 'openclaw_ssh_port';
    case SSH_USER = 'openclaw_ssh_user';
    case SSH_PRIVATE_KEY = 'openclaw_ssh_private_key';
    case OPENCLAW_HOME = 'openclaw_home';
    case GATEWAY_TOKEN = 'openclaw_gateway_token';
}
