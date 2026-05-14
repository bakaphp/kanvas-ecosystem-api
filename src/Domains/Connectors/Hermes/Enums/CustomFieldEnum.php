<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Enums;

enum CustomFieldEnum: string
{
    case HERMES_AGENT_ID = 'HERMES_AGENT_ID';
    case HERMES_WORKSPACE_PATH = 'HERMES_WORKSPACE_PATH';
    case HERMES_DEPLOYMENT_STATUS = 'HERMES_DEPLOYMENT_STATUS';
    case HERMES_DEPLOYMENT_ID = 'HERMES_DEPLOYMENT_ID';
    case HERMES_GATEWAY_TOKEN = 'HERMES_GATEWAY_TOKEN';
}
