<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\DailyLearning\Services;

use Illuminate\Support\Collection;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentConversation;

/**
 * Converts a day's worth of an agent's conversations into a single LLM prompt.
 * Strips both tool_result rows AND empty-content assistant tool-call rows,
 * truncates long content — the LLM sees the human↔agent dialogue only so
 * extracted learnings come from substance, not tool noise.
 */
final class DailyLearningPromptBuilderService
{
    private const int MAX_CONTENT_CHARS = 800;

    private const string ROLE_USER = 'user';

    private const string ROLE_ASSISTANT = 'assistant';

    /**
     * @param Collection<int, AgentConversation> $conversations  must have messages eager-loaded
     */
    public function build(Agent $agent, string $cycleDateLabel, Collection $conversations): string
    {
        $instructions = $this->instructions($agent, $cycleDateLabel);
        $transcript = $this->transcript($conversations);

        return $instructions . "\n\nCONVERSATIONS:\n" . $transcript;
    }

    private function instructions(Agent $agent, string $cycleDateLabel): string
    {
        // Agent.name is docblocked as non-null but real data sometimes has it
        // empty — handle both with a `?:` fallback. getId() returns mixed per
        // the trait, hence the explicit (int) cast for concatenation.
        $agentName = $agent->name !== '' ? $agent->name : ('agent #' . (int) $agent->getId());

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

        foreach ($conversation->messages()->orderBy('created_at')->orderBy('id')->cursor() as $message) {
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
