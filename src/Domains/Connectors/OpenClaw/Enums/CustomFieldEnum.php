<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Enums;

enum CustomFieldEnum: string
{
    case OPENCLAW_AGENT_ID = 'OPENCLAW_AGENT_ID';
    case OPENCLAW_WORKSPACE_PATH = 'OPENCLAW_WORKSPACE_PATH';
    case OPENCLAW_DEPLOYMENT_STATUS = 'OPENCLAW_DEPLOYMENT_STATUS';
    case OPENCLAW_DEPLOYMENT_ID = 'OPENCLAW_DEPLOYMENT_ID';
}
