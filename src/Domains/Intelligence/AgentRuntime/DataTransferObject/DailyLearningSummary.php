<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\DataTransferObject;

use Spatie\LaravelData\Data;

/**
 * Output of the daily LLM summarization pass over an agent's conversations,
 * split into three lifecycles so each field lands in the right destination:
 *
 *  - Humans (ephemeral): briefing + proposed_actions → AgentDailyCycle + digest email
 *  - The agent itself (durable): durable_facts → MEMORY.md, deduped + capped
 *  - Analytics (tracked): skills_emerged + self_improvement_score → AgentDailyCycle
 *
 * The LLM prompt is responsible for the ephemeral-vs-durable triage: the
 * persistence layer trusts whichever field the LLM placed each item in.
 *
 * Safe to queue — only primitives, no Eloquent models.
 *
 * The three lists default to empty: the schema does not require them, and a model with nothing to
 * propose leaves the key out instead of sending `[]`. A required list fails the whole summary on
 * that reply (KANVAS-ECOSYSTEM-703).
 */
final class DailyLearningSummary extends Data
{
    public function __construct(
        // 2-3 paragraph narrative of yesterday for human consumption.
        public readonly string $briefing,

        // 0.0–0.5 same scale as RecordAgentDailyCycleAction's existing scoring.
        public readonly float $self_improvement_score,

        // Today-only todo list. Lands in AgentDailyCycle.proposed_actions.
        /** @var list<string> */
        public readonly array $proposed_actions = [],

        // One-line declarative statements still useful in 30 days
        // ("Reynaldo handles POs.", "EVT starts 1 workday after sample receipt.").
        // Appended to MEMORY.md in §-separated format, deduped against existing entries.
        /** @var list<string> */
        public readonly array $durable_facts = [],

        // Tracked over time, scored. Lands in AgentDailyCycle.skills_emerged.
        /** @var list<array{name: string, confidence: float}> */
        public readonly array $skills_emerged = [],
    ) {
    }
}
