<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrowserUse\Enums;

enum ConfigurationEnum: string
{
    /**
     * The company's own Browser Use workspace, created on first use. Sessions run with no workspace by
     * default, and then everything the agent writes dies with the sandbox.
     */
    case WORKSPACE_ID = 'browser_use_workspace_id';

    public function getValue(): string
    {
        return $this->value;
    }
}
