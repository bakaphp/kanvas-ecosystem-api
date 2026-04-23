<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Lendflow\Enums;

enum ConfigurationEnum: string
{
    case API_KEY = 'LENDFLOW_API_KEY';
    case WORKFLOW_TEMPLATE_ID = 'LENDFLOW_WORKFLOW_TEMPLATE_ID';
    case USE_SANDBOX = 'LENDFLOW_USE_SANDBOX';
}
