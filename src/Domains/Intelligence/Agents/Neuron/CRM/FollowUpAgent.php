<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\CRM;

use Illuminate\Support\Facades\Blade;
use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Neuron\BaseKanvasAgent;
use Kanvas\Intelligence\Agents\Neuron\SalesAssistKanvasMessageHistory;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\InMemoryChatHistory;
use Override;

/**
 * Initiation-shape agent (no inbound text — kernel fed a synthetic "decide
 * what to do" prompt). Always returns strict JSON; the contract is appended
 * to the role-defined output so it's enforced even when role copy changes.
 *
 * Role override: when $agent->role['background'|'steps'|'output'] has content,
 * DB wins. Otherwise the LOCAL_* defaults below are used.
 */
#[AgentTypeDefinition(
    name: 'Follow-Up Agent',
    description: 'Re-engages idle leads after a silence window — decides whether to send a nudge, advance the pipeline stage, or stand down. Returns a strict JSON decision.',
    provider: 'neuron',
)]
class FollowUpAgent extends BaseKanvasAgent
{
    private const LOCAL_BACKGROUND = 'You are the follow-up engager. You re-engage idle leads after a silence window and decide whether to send a nudge, advance the pipeline stage, or stand down.';

    private const LOCAL_STEPS = 'Read the conversation history for the lead. Weigh tone, prior touches, explicit objections. Decide should_respond + advance_stage. Compose a concise message when sending.';

    private const LOCAL_OUTPUT = 'Strict JSON only. Match the contract appended below.';

    private const JSON_CONTRACT = <<<'CONTRACT'
        RESPONSE FORMAT — STRICT JSON ONLY. No prose. No markdown fences. No leading or trailing text.

        Return a JSON object with exactly these keys:

          {
            "should_respond": boolean,   // true to send the message body now, false to skip this turn
            "advance_stage":  boolean,   // true to move the lead to the next pipeline stage
            "message":        string|null,  // the body to send; null when should_respond is false
            "reason":         string         // one-sentence rationale (logged for audit)
          }

        Decision guidance:

          - Silent leads with prior unanswered touches → prefer should_respond=false; explain in `reason`.
          - Explicit disinterest / "stop contacting me" / completed via another channel → both false.
          - Stage goal reached (meeting confirmed / signed / paid) → advance_stage=true.
          - When in doubt and no prior nudge in this stage → send a polite, contextual message.

        Output ONLY the JSON object. Any other content will be rejected.
        CONTRACT;

    #[Override]
    protected function chatHistory(): AbstractChatHistory
    {
        if ($this->entity === null || $this->user === null) {
            return new InMemoryChatHistory();
        }

        return new SalesAssistKanvasMessageHistory(
            app: $this->app,
            company: $this->company,
            user: $this->user,
            entity: $this->entity,
            threadId: $this->threadId,
            currentLead: $this->currentLead,
        );
    }

    #[Override]
    public function instructions(): string
    {
        $role = (array) ($this->agent->role ?? []);
        $context = ['lead' => $this->resolveLeadForTurn()];

        $background = $this->renderOrDefault($role['background'] ?? null, self::LOCAL_BACKGROUND, $context);
        $steps = $this->renderOrDefault($role['steps'] ?? null, self::LOCAL_STEPS, $context);
        $output = $this->renderOrDefault($role['output'] ?? null, self::LOCAL_OUTPUT, $context);

        return new SystemPrompt(
            background: explode("\n", $background),
            steps: explode("\n", $steps),
            output: array_merge(
                explode("\n", $output),
                explode("\n", self::JSON_CONTRACT),
            ),
        )->__toString();
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderOrDefault(mixed $dbValue, string $localDefault, array $context): string
    {
        if (is_string($dbValue) && trim($dbValue) !== '') {
            return Blade::render($dbValue, $context);
        }

        return $localDefault;
    }
}
