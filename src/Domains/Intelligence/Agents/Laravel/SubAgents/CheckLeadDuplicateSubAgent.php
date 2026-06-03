<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\SubAgents;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\KanvasAgentAsTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Guild\LeadSearchTool;
use Override;
use Stringable;

#[AgentTool(name: 'Check Lead Duplicate')]
class CheckLeadDuplicateSubAgent extends KanvasAgentAsTool
{
    #[Override]
    public function name(): string
    {
        return 'check_lead_duplicate';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Check if the given text describes a corporate event already recorded as a lead. Call this BEFORE create_lead. Returns is_duplicate, lead_id, and reason.';
    }

    #[Override]
    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
        You are a corporate event deduplication specialist.
        You receive a new text describing a corporate distress event and a list of existing leads.
        Your task: determine if the new text describes the SAME event as any existing lead — even if the wording is slightly different.

        Same event = same company + same event type (bankruptcy, layoff, acquisition, etc.) within the same time window.

        Always call search_leads first with the company name extracted from the text.

        Respond ONLY with a JSON object:
        {"is_duplicate": bool, "lead_id": int|null, "confidence": "high|medium|low", "reason": "..."}
        INSTRUCTIONS;
    }

    #[Override]
    public function agentTools(): iterable
    {
        return [new LeadSearchTool()];
    }
}
