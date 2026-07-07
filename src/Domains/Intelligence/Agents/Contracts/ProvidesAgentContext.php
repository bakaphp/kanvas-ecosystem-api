<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Contracts;

/**
 * Opt-in on any model to control how it is summarized to an agent that is
 * dropped onto it. Without this, EntityContextBriefService falls back to a
 * generic attribute-based brief.
 */
interface ProvidesAgentContext
{
    /**
     * A compact, structured brief an agent reads to ground itself on this record.
     *
     * @return array<string, mixed>
     */
    public function agentContextBrief(): array;
}
