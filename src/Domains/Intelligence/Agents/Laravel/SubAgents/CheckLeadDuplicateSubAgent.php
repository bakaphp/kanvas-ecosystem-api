<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\SubAgents;

use Illuminate\Support\Str;
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
        return Str::slug(AgentTool::fromClass($this)?->name ?? class_basename($this), '_');
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
        You receive a new text describing a corporate distress event.
        Your task: determine if this text describes the SAME event as any existing lead.

        ## Search strategy — run BOTH calls

        Call 1 — Exact match (same company AND same field value):
          If exact_field and exact_value are present in your input, use them directly:
          search_leads(
            query: <company_name extracted from the text>,
            exact_field: <exact_field from input>,
            exact_value: <exact_value from input>,
            days_back: 180
          )
          Any result from this call = duplicate. HIGH confidence. Stop immediately.

        Call 2 — Semantic match (same company, different wording):
          search_leads(
            query: <company_name>,
            event_sub_type: <event sub-type if known>,
            days_back: 180
          )

        ## Decision rules

        - If Call 1 returns ANY result → is_duplicate = true, HIGH confidence. Stop immediately.
        - If Call 2 returns leads from the same company with the same event type within the same time window → is_duplicate = true.
        - If no results in either call → is_duplicate = false.

        Same event = same company + same event type (bankruptcy, layoff, acquisition, etc.) within the same time window.

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
