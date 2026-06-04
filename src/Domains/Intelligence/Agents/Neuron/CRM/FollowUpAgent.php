<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\CRM;

use Illuminate\Support\Facades\Blade;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\BaseKanvasAgent;
use Kanvas\Intelligence\Agents\Neuron\SalesAssistKanvasMessageHistory;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\InMemoryChatHistory;
use Override;

/**
 * Dedicated agent for follow-up nudges. Different from {@see SalesAgent} in
 * three ways:
 *
 *   1. Initiation-shape — no inbound customer text. The kernel is fed a
 *      synthetic "decide what to do for this lead in stage X" message; the
 *      agent's job is to read the conversation history (rolled up across
 *      channels via SalesAssistKanvasMessageHistory) and emit a structured
 *      decision.
 *
 *   2. Structured output — instructions append a strict JSON contract
 *      (see APPENDIX below). The action layer parses with
 *      {@see \Kanvas\Intelligence\FollowUp\DataTransferObject\AgentFollowUpResult::fromKernelResponse}.
 *      The agent's role.background/steps/output (configured per tenant on
 *      the Agent row) still drives tone/voice — the JSON contract is appended
 *      so it's always present even when role copy is updated.
 *
 *   3. No tools — follow-up is a one-shot decision, not a conversational
 *      back-and-forth. Tools would let the agent stall by calling them
 *      instead of returning the decision. Add tools later only if a
 *      follow-up specifically requires them (e.g. read inventory before
 *      offering an alternative).
 *
 * Wire by creating an `agent_types` row whose `handler` column is
 * `Kanvas\Intelligence\Agents\Neuron\CRM\FollowUpAgent` and pointing the
 * tenant's `FollowUpEngagerAgent` Agent row at that type. The {@see Lead}
 * tied to the per-turn invocation is plumbed via setCurrentLead().
 */
class FollowUpAgent extends BaseKanvasAgent
{
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
        $role = $this->agent->role ?? [];
        $lead = $this->currentLead
            ?? ($this->entity instanceof Lead ? $this->entity : null);

        $background = Blade::render((string) ($role['background'] ?? ''), ['lead' => $lead]);
        $steps = Blade::render((string) ($role['steps'] ?? ''), ['lead' => $lead]);
        $output = Blade::render((string) ($role['output'] ?? ''), ['lead' => $lead]);

        // The structured-output contract is appended to whatever output guidance
        // the tenant configured in agent.role['output']. Order matters — by
        // putting the JSON contract LAST we make it the closest instruction
        // to the model's response, which improves adherence in practice.
        $jsonContract = <<<'CONTRACT'
            RESPONSE FORMAT — STRICT JSON ONLY. No prose. No markdown fences. No leading or trailing text.

            Return a JSON object with exactly these keys:

              {
                "should_respond": boolean,   // true to send the message body now, false to skip this turn
                "advance_stage":  boolean,   // true to move the lead to the next pipeline stage
                "message":        string|null,  // the body to send; null when should_respond is false
                "reason":         string         // one-sentence rationale (will be logged for audit)
              }

            Decision guidance:

              - If conversation history shows the lead has been silent for an unusually long time AND prior
                touches were unanswered, prefer should_respond=false and explain the disengagement signal in `reason`.

              - If the lead has clearly indicated disinterest, asked to stop being contacted, or already
                completed the outcome via another channel: should_respond=false, advance_stage=false.

              - If the goal of the current stage has been reached (e.g. they confirmed a meeting / signed /
                paid), set advance_stage=true. Set should_respond=false unless a confirmation message is
                still warranted; set both to true if you want to send a confirmation AND advance.

              - When in doubt and there's been no prior nudge in this stage, send a polite, contextual message.

            Output ONLY the JSON object. Any other content will be rejected.
            CONTRACT;

        return new SystemPrompt(
            background: explode("\n", $background),
            steps: explode("\n", $steps),
            output: array_merge(
                explode("\n", $output),
                explode("\n", $jsonContract),
            ),
        )->__toString();
    }
}
