<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Enums;

/**
 * Mirrors OpenCode's `PermissionV2Reply` enum exactly — the server rejects anything else, and
 * "allow" (the obvious guess) is not one of them.
 */
enum PermissionDecisionEnum: string
{
    case ONCE = 'once';
    case ALWAYS = 'always';
    case REJECT = 'reject';

    public function isGrant(): bool
    {
        return $this !== self::REJECT;
    }
}
