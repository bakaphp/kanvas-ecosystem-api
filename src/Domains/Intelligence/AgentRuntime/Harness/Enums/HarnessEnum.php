<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Enums;

enum HarnessEnum: string
{
    case OPENCODE = 'opencode';
    case PIDEV = 'pidev';
    case CLAUDE = 'claude';

    /**
     * Only OpenCode runs on infrastructure Kanvas owns; the others are remote services that keep
     * their own container, so machine/worktree/port columns stay null for them.
     */
    public function isSelfHosted(): bool
    {
        return $this === self::OPENCODE;
    }
}
