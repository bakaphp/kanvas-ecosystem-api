<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\DailyLearning\Services;

use Illuminate\Support\Collection;
use Kanvas\Connectors\Hermes\Services\HermesMemoryBlockBuilderService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentConversation;
use Kanvas\Intelligence\Agents\Models\AgentConversationMessage;

/**
 * Converts a day's worth of an agent's conversations into a single LLM prompt.
 * Strips both tool_result rows AND empty-content assistant tool-call rows,
 * truncates long content — the LLM sees the human↔agent dialogue only so
 * extracted learnings come from substance, not tool noise.
 *
 * When the caller passes an `$existingMemory` blob, it's parsed via the
 * Hermes `§`-format and injected into the prompt so the LLM can semantically
 * dedup `durable_facts` upstream — the first-50-chars-lowercased heuristic
 * in HermesMemoryBlockBuilderService misses re-phrasings, the LLM doesn't.
 */
final class DailyLearningPromptBuilderService
{
    private const int MAX_CONTENT_CHARS = 800;

    private const string ROLE_USER = 'user';

    private const string ROLE_ASSISTANT = 'assistant';

    /**
     * @param Collection<int, AgentConversation> $conversations  must have messages eager-loaded
     * @param string                              $existingMemory raw `§`-separated MEMORY.md text from the runtime; '' if none/unsupported
     */
    public function build(
        Agent $agent,
        string $cycleDateLabel,
        Collection $conversations,
        string $existingMemory = '',
    ): string {
        $instructions = $this->instructions($agent, $cycleDateLabel);
        $existingBlock = $this->existingMemoryBlock($existingMemory);
        $transcript = $this->transcript($conversations);

        return $instructions . $existingBlock . "\n\nCONVERSATIONS:\n" . $transcript;
    }

    /**
     * Renders the "facts already in the agent's memory" block, or '' when
     * there's nothing prior. Sits between the instructions and the conversation
     * transcript so the LLM sees the dedup rules in the same scroll as the
     * existing facts.
     */
    private function existingMemoryBlock(string $existingMemory): string
    {
        $facts = HermesMemoryBlockBuilderService::parseFacts($existingMemory);
        if ($facts === []) {
            return '';
        }

        $rendered = array_map(static fn (string $f) => '  • ' . $f, $facts);
        $count = count($facts);

        return "\n\nEXISTING DURABLE MEMORY ({$count} facts already in the agent's memory bank):\n"
            . implode("\n", $rendered)
            . "\n\n"
            . "DEDUP RULES for `durable_facts`:\n"
            . "- Do NOT re-emit anything semantically covered by the existing memory above, "
            . "even if the wording differs.\n"
            . "- A fact is REDUNDANT if it restates an existing fact with different phrasing, "
            . "is a more verbose version of an existing fact, or repeats a subset of a richer "
            . "existing fact (e.g. if memory already lists three Google Sheet IDs, do NOT emit "
            . "them again as separate facts).\n"
            . "- A fact is NEW if it adds information the existing memory does not capture: "
            . "a different person/system, an additional constraint, a corrected/updated value, "
            . "or a discovered platform limitation.\n"
            . "- Emit CORRECTIONS as new facts (e.g. \"Reynaldo handles POs now (transitioned "
            . "from Kimberly)\" supersedes an older Kimberly fact).\n"
            . "- If yesterday only CONFIRMED existing memory without producing genuinely new "
            . "durable knowledge, return an empty `durable_facts` array. This is the expected "
            . "and correct outcome on most days.";
    }

    private function instructions(Agent $agent, string $cycleDateLabel): string
    {
        // Agent.name is docblocked as non-null but real data sometimes has
        // it empty *or* null — cast first so both fall through to the id
        // fallback. getId() returns mixed per the trait, hence the (int) cast.
        $rawName = (string) ($agent->name ?? '');
        $agentName = $rawName !== '' ? $rawName : ('agent #' . (int) $agent->getId());

        return <<<PROMPT
You are analyzing a day's worth of conversations for an autonomous agent named "{$agentName}". The conversations are from {$cycleDateLabel} in the agent's company timezone.

Produce a structured daily learning summary in the schema provided.

INSTRUCTIONS for each field:

- `briefing`: 2-3 paragraph narrative of what happened, written first-person from the agent's perspective ("I helped X with Y…"). Audience: humans reviewing the agent's work.

- `proposed_actions`: TODAY's todo list — concrete actions to take based on yesterday's pending items. 3 to 8 items. Each one short, imperative ("Check the Schedule Master for slipped dates.").

- `durable_facts`: One-line declarative statements that will still be true and useful in 30 days. THIS IS THE MOST IMPORTANT FIELD — these facts get written into the agent's persistent memory bank and read on every future prompt.
  GOOD examples (single sentence, declarative, durable):
    • "Reynaldo handles POs."
    • "EVT validation starts 1 workday after sample receipt (skip weekends)."
    • "The Schedule Master sheet uses reverse-chronological ordering."
    • "Steven Lu is the contact for PNP and check-ins."
  BAD examples (these are ephemeral — put in `briefing` instead, NOT here):
    • "I helped Kevin with the gate meeting today."
    • "I processed 8 conversations on Slack."
    • "Today I answered a question about memory architecture."
  Return 0 to 15 items. Empty array if no genuinely new durable facts emerged. Period at end of each.

- `skills_emerged`: Capability patterns the agent demonstrated yesterday. Each has a short kebab-case name and a confidence float between 0 and 1. 0 to 10 items.

- `self_improvement_score`: 0.0 to 0.5. Your judgment of how much the agent improved its capabilities yesterday.
PROMPT;
    }

    /**
     * @param Collection<int, AgentConversation> $conversations
     */
    private function transcript(Collection $conversations): string
    {
        $blocks = [];
        foreach ($conversations as $conversation) {
            $block = $this->renderConversation($conversation);
            if ($block !== '') {
                $blocks[] = $block;
            }
        }

        return $blocks === [] ? '(no conversations)' : implode("\n\n", $blocks);
    }

    private function renderConversation(AgentConversation $conversation): string
    {
        $title = trim((string) $conversation->title);
        $source = is_array($conversation->meta) && isset($conversation->meta['source'])
            ? (string) $conversation->meta['source']
            : 'unknown';

        $lines = [];
        $lines[] = sprintf('[Conversation: %s — %s]', $title === '' ? '(untitled)' : $title, $source);

        // Walk the eager-loaded relation, NOT a fresh `messages()` query
        // builder — the caller (SummarizeAgentDailyLearningAction) eager-loads
        // with a day-window `whereBetween('created_at', [...])` constraint;
        // re-opening the relation here would discard the window and leak
        // older messages into yesterday's summary.
        $messages = $conversation->messages
            ->sortBy(fn (AgentConversationMessage $m): array => [$m->created_at->getTimestamp(), $m->id])
            ->values();

        foreach ($messages as $message) {
            $rendered = $this->renderMessage($message);
            if ($rendered !== null) {
                $lines[] = $rendered;
            }
        }

        // A conversation with only its header line and no content has no signal.
        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    /**
     * Returns null if the message contributes no human-readable signal
     * (tool results, empty-content assistant tool dispatches).
     */
    private function renderMessage(mixed $message): ?string
    {
        $role = $message->role;
        if ($role !== self::ROLE_USER && $role !== self::ROLE_ASSISTANT) {
            return null;
        }

        $content = trim((string) ($message->content ?? ''));
        if ($content === '') {
            // Assistant message with empty content was a tool-call dispatch — no signal.
            return null;
        }

        if (mb_strlen($content) > self::MAX_CONTENT_CHARS) {
            $content = mb_substr($content, 0, self::MAX_CONTENT_CHARS) . '…[truncated]';
        }

        // Collapse internal newlines so each message stays on one line in the prompt.
        $content = preg_replace('/\s+/', ' ', $content) ?? $content;

        $ts = $message->created_at?->format('Y-m-d H:i') ?? '?';
        $label = $role === self::ROLE_USER ? 'USER' : 'ASSISTANT';

        return sprintf('%s (%s): %s', $label, $ts, $content);
    }
}
