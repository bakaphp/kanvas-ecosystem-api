<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\AgentRuntime\Enums\AgentChannelTokenEnum;
use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Contracts\ConversesWithUser;
use Kanvas\Intelligence\Agents\Neuron\History\ChannelMessageHistory;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\CancelScheduledActionTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\ListScheduledActionsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\ScheduleAgentTaskTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\ScheduleReminderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\ReadEntityContextTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\ReadMyLedgerTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\ReadUserActivityTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\SendEmailToUserTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\SendSlackDirectMessageTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\WhoIsUserTool;
use Kanvas\Intelligence\Agents\Services\EntityContextBriefService;
use Kanvas\Intelligence\Agents\Traits\MergesRegisteredTools;
use Kanvas\NervousSystem\Capability\Enums\CapabilityFrameworkEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Users\Models\Users;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\InMemoryChatHistory;
use Override;

/**
 * A domain "system agent" that IS a Kanvas user and talks to people like a teammate.
 *
 * Memory is assembled per turn from where the conversation is happening, not from a
 * single chat store:
 *  - Dropped onto a CRM entity (Lead/People) → the entity's cross-channel rollup, so
 *    the agent reads the whole prior thread before it replies.
 *  - Talking directly to a person (entity is a Users) or running a company-scoped
 *    command (no entity) → a per-session store keyed on the session, like a DM.
 *
 * Domain memory ("what have I done") comes from the ledger via tools (PR3), not from
 * this conversation store — the two are kept separate on purpose.
 */
#[AgentTypeDefinition(
    name: 'System User Agent',
    description: 'A domain system agent that is a Kanvas user — reachable by any channel, droppable onto any entity, powered by its registered tool set.',
    provider: 'neuron',
    soul: 'You are a capable Kanvas system agent acting as a member of the team. You act through your tools and are accountable for what you do.',
    outputFormat: 'Plain text. Short paragraphs; lists only when enumerating distinct items.',
)]
class SystemUserAgent extends BaseRagAgent implements ConversesWithUser
{
    use MergesRegisteredTools;

    private ?Channel $mentionChannel = null;

    #[Override]
    protected function usesOrganizationWideKnowledge(): bool
    {
        return true;
    }

    /**
     * Answering an @mention: read the WHOLE channel this turn, not the per-session store.
     */
    public function setMentionChannel(?Channel $channel): void
    {
        $this->mentionChannel = $channel;
    }

    #[Override]
    protected function chatHistory(): AbstractChatHistory
    {
        if ($this->mentionChannel !== null) {
            return new ChannelMessageHistory($this->mentionChannel);
        }

        $app = $this->app;
        $company = $this->company;
        $user = $this->user;

        if ($app === null || $company === null || $user === null) {
            return new InMemoryChatHistory();
        }

        if ($this->usesEntityRollup() && $this->entity !== null) {
            return new SalesAssistKanvasMessageHistory(
                app: $app,
                company: $company,
                user: $user,
                entity: $this->entity,
                includeInternal: true,
                currentLead: $this->currentLead,
            );
        }

        return new KanvasMessageHistory(
            app: $app,
            company: $company,
            user: $user,
            agentClass: static::class,
            sessionId: $this->threadId ?? $this->session?->uuid,
            agent: $this->agent,
            turnMedia: $this->turnMedia,
            model: $this->resolvedModelName(),
            privateUserTurn: $this->privateUserTurn,
        );
    }

    /**
     * KanvasMessageHistory self-persists each turn, so RunNeuronChatAction must skip
     * logTurn there. The SalesAssist rollup writes to Social and leaves logTurn as its
     * usage record — mirror that per branch.
     */
    #[Override]
    public function persistsTurnsToConversationStore(): bool
    {
        // Mention replies are stored as a child message by the responder — skip logTurn.
        if ($this->mentionChannel !== null) {
            return true;
        }

        return $this->app !== null
            && $this->company !== null
            && $this->user !== null
            && ! $this->usesEntityRollup();
    }

    /**
     * Prepend the agent's own identity (it IS a Kanvas user) and ground it on the
     * record it was dropped onto. Both are deterministic — true even for facts never
     * said in the thread — so the agent never has to guess who it is or who it's helping.
     */
    #[Override]
    public function instructions(): string
    {
        $instructions = $this->selfIdentityBlock() . parent::instructions();

        $subject = $this->subjectEntity();
        if ($subject !== null) {
            $instructions .= "\n\n## Who you're helping with right now\n"
                . new EntityContextBriefService()->renderText($subject);
        }

        return $instructions;
    }

    private function selfIdentityBlock(): string
    {
        $agent = $this->agent;
        if ($agent === null) {
            return '';
        }

        $lines = [
            '## Who you are',
            sprintf(
                'You are "%s", a Kanvas system agent — a real member of the team%s, built into Kanvas (not a generic external assistant).',
                $agent->name,
                $this->company !== null ? ' at ' . $this->company->name : '',
            ),
        ];

        $user = $agent->user;
        if ($user !== null) {
            $fullName = trim($user->firstname . ' ' . $user->lastname);
            $handle = $user->getAppDisplayName();

            $lines[] = sprintf(
                'You ARE a Kanvas user: %s (email %s, user id %d). You act as this user — every record and ledger '
                . 'event you create is stamped under this identity. When asked which user you are, this is the answer.',
                $fullName !== '' ? $fullName : $handle,
                (string) $user->email,
                $user->getId(),
            );

            if ($handle !== '') {
                $lines[] = sprintf(
                    'Your display name is "%s" — this is your @mention handle; teammates reach you by typing @%s in a channel.',
                    $handle,
                    $handle,
                );
            }
        }

        return implode("\n", $lines) . "\n\n";
    }

    /**
     * @return list<object>
     */
    #[Override]
    protected function tools(): array
    {
        $app = $this->app;
        $company = $this->company;
        $agent = $this->agent;

        if ($app === null || $company === null || $agent === null) {
            return [];
        }

        $core = $this->identityTools();

        $subject = $this->subjectEntity();
        if ($subject !== null) {
            $core[] = new ReadEntityContextTool($subject);
            $core[] = new ReadUserActivityTool($app, $company, $subject);
        }

        $core[] = new SendEmailToUserTool($agent);

        // The schedule tools key on the human, not the agent: "remind me" must land on the person
        // who asked. On an @mention surface $this->user IS the agent's own user, so an explicit
        // conversation human (set by the caller) wins over it.
        $contextUser = $this->conversationHuman ?? $this->user ?? $agent->user;
        $core[] = new ScheduleReminderTool($agent, $this->session)->withContext($app, $company, $contextUser);
        $core[] = new ScheduleAgentTaskTool($agent, $this->session)->withContext($app, $company, $contextUser);
        $core[] = new ListScheduledActionsTool($this->session)->withContext($app, $company, $contextUser);
        $core[] = new CancelScheduledActionTool($this->session)->withContext($app, $company, $contextUser);

        if ((string) ($agent->get(AgentChannelTokenEnum::SLACK_BOT_TOKEN->value) ?? '') !== '') {
            $core[] = new SendSlackDirectMessageTool($agent);
        }

        return $this->mergeRegisteredTools(
            $core,
            $agent,
            CapabilityFrameworkEnum::NEURON
        );
    }

    /**
     * Identity/memory tools for an internal-teammate agent: who it's talking to + its own ledger
     * memory. Exposed so subclasses (e.g. the PM, whose entity is a Project not a person) reuse it.
     * who_is_user targets the session entity when that's a Users, else the authenticated human.
     *
     * @return list<object>
     */
    protected function identityTools(): array
    {
        $app = $this->app;
        $company = $this->company;
        $agent = $this->agent;

        if ($app === null || $company === null || $agent === null) {
            return [];
        }

        return [
            new ReadMyLedgerTool($app, $company, $agent),
            new WhoIsUserTool(
                $app,
                $company,
                $this->entity instanceof Users ? $this->entity : $this->user
            ),
        ];
    }

    /**
     * A system agent acts as ITSELF — a registry tool's Users dependency resolves to the
     * agent's own dedicated user (via BaseKanvasAgent's type-based tool DI), so records it
     * creates are stamped under the agent's identity rather than the human it's talking to.
     */
    #[Override]
    protected function actingUser(): ?Users
    {
        return $this->agent?->user ?? $this->user;
    }

    private function usesEntityRollup(): bool
    {
        return $this->entity instanceof Lead || $this->entity instanceof People;
    }

    /**
     * The record the agent is acting on — the per-turn lead in context wins (internal DM
     * about a lead), otherwise any non-Users session entity (dropped onto a record). Null
     * in a plain DM or a company-scoped command. Distinct from the human it's talking to.
     */
    private function subjectEntity(): ?Model
    {
        if ($this->currentLead !== null) {
            return $this->currentLead;
        }

        if ($this->entity === null || $this->entity instanceof Users) {
            return null;
        }

        return $this->entity;
    }
}
