<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Activities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Throwable;

/**
 * Hand a record to an agent and let it work — the piece every other agent activity was missing.
 *
 * Every existing agent trigger is a RESPONDER: it answers back on the channel the record came from.
 * That is wrong for an agent that should read something and act somewhere else — an agent watching a
 * customer's WhatsApp group to draft articles would post its drafts into that group. This one runs the
 * agent with the record in scope, gives it its tools, and posts nothing: whatever the agent does, it
 * does through its tools.
 */
#[WorkflowAction(
    name: 'Run Agent On Record',
    description: 'Wakes an agent with the record that triggered the rule and lets it work using its own '
        . 'tools. It does NOT reply on the record\'s channel and does not post anywhere — the agent acts '
        . 'only through the tools it has been granted. Use this for read-and-act work: judge an inbound '
        . 'message, draft something, file a record, decide whether anything is needed at all. Use a '
        . 'channel responder activity instead when the agent should answer the person who wrote in. '
        . 'Works on a record trigger (Message created) and on a channel trigger '
        . '(after-adding-message-to-channel) alike — on the channel trigger the agent is given the '
        . 'message that arrived, not the channel.',
    integration: IntegrationsEnum::INTERNAL,
    requiredParams: ['agent_id'],
    params: [
        'agent_id' => 'The agent to wake. It must belong to this company.',
        'instruction' => 'Optional preamble put in front of the record, for when this particular '
            . 'workflow needs to frame it — "this is a support channel, decide whether it needs a '
            . 'reply". Leave it out and the record goes to the agent on its own, which is usually '
            . 'right: the agent already knows its job from its own instructions. When you do set one, '
            . 'say that doing nothing is allowed, or the agent will find something to do every time.',
    ],
)]
class RunAgentOnEntityActivity extends KanvasActivity
{
    public $tries = 3;

    // Enough for the agent to recognise what it is looking at; the full record and the surrounding
    // conversation come from its tools.
    private const int PREVIEW_CHARS = 500;

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function execute(Model $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        $agentId = (int) ($params['agent_id'] ?? 0);
        $instruction = trim((string) ($params['instruction'] ?? ''));

        if ($agentId === 0) {
            return $this->failWorkflow([
                'message' => 'No agent_id was configured on this rule, so there is nobody to wake.',
                'entity' => null,
            ]);
        }

        try {
            /** @var Agent $agent */
            $agent = Agent::getById($agentId, $app);
        } catch (Throwable $e) {
            return $this->failWorkflow([
                'message' => 'Agent ' . $agentId . ' was not found for this app: ' . $e->getMessage(),
                'entity' => null,
            ]);
        }

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: fn (): array => $this->wake(
                $agent,
                $this->recordInScope($entity, $params),
                $instruction
            ),
            company: $this->resolveCompany($agent, $entity),
        );
    }

    /**
     * What the agent is actually being asked to judge, which is not always the rule's entity.
     *
     * A channel trigger fires on the CHANNEL and hands the message that arrived in `params.message`
     * — that message is the record, and using the channel instead would compare the loop guard
     * against the channel's owner rather than the message's author, disabling it entirely. A record
     * trigger has no such param and the entity is already the record.
     *
     * @param array<string, mixed> $params
     */
    protected function recordInScope(Model $entity, array $params): Model
    {
        $message = $params['message'] ?? null;

        return $message instanceof Model ? $message : $entity;
    }

    /**
     * @return array<string, mixed>
     */
    protected function wake(Agent $agent, Model $entity, string $instruction): array
    {
        if (! $agent->is_active) {
            return $this->failWorkflow([
                'message' => 'Agent ' . $agent->getId() . ' is not active.',
                'entity' => null,
            ]);
        }

        if ($this->isOwnWork($agent, $entity)) {
            // Without this the agent's own output re-fires the rule that produced it. A rule on
            // Message/created whose agent writes a Message is an unbounded loop, and it is the natural
            // shape of every "read this, write that" workflow.
            return $this->failWorkflow([
                'message' => 'The record was created by this agent, skipping to avoid waking it on its '
                    . 'own work.',
                'entity' => null,
            ]);
        }

        $user = $agent->user;

        if ($user === null) {
            return $this->failWorkflow([
                'message' => 'Agent ' . $agent->getId() . ' has no user, so it cannot act.',
                'entity' => null,
            ]);
        }

        $prompt = $this->wakePrompt($entity, $instruction);

        if ($prompt === '') {
            return $this->failWorkflow([
                'message' => 'The record has no content and the rule sets no instruction, so there is '
                    . 'nothing to give the agent.',
                'entity' => null,
            ]);
        }

        // A failed model call is a real fault, not an expected skip, so it is deliberately not caught
        // here — executeIntegration reports it and it reaches Sentry.
        $response = new AgentChatKernel(
            agent: $agent,
            session: $this->sessionFor($agent, $entity),
            message: $prompt,
            user: $user,
            // No sourceChannel or sourceMessage, and nothing persisted: this is what keeps the run
            // silent. Handing either of those in would post the agent's output back to the record's
            // channel, which is what this activity exists to avoid.
            persistConversation: false,
        )->execute();

        return [
            'message' => 'Agent ' . $agent->name . ' ran on ' . $entity::class . ' ' . $entity->getKey(),
            'response' => $response,
            'entity' => $entity,
        ];
    }

    /**
     * No scaffolding around the record: the agent knows its job from its own instructions, and being
     * called is how it knows it was woken. What it does not have is the record, so that is all it
     * gets — with the rule's preamble in front of it only when one is set.
     */
    protected function wakePrompt(Model $entity, string $instruction): string
    {
        return trim($instruction . "\n\n" . (string) $this->previewOf($entity));
    }

    protected function channelOf(Model $entity): ?Channel
    {
        if ($entity instanceof Channel) {
            return $entity;
        }

        if (! method_exists($entity, 'channels')) {
            return null;
        }

        $channel = $entity->channels()->first();

        return $channel instanceof Channel ? $channel : null;
    }

    /**
     * Kept short on purpose: enough for the agent to know what it is looking at, not a substitute for
     * reading the record properly through a tool.
     */
    protected function previewOf(Model $entity): ?string
    {
        if (! method_exists($entity, 'contentText')) {
            return null;
        }

        $text = trim((string) $entity->contentText());

        return $text === '' ? null : Str::limit($text, self::PREVIEW_CHARS);
    }

    /**
     * One thread per CHANNEL, not per record.
     *
     * A channel is a conversation, and the session is what carries it — so an agent woken on the
     * tenth message already holds the first nine and needs only the new one. Keying per record would
     * start every wake from nothing, and the context would then have to be pasted into each prompt
     * to compensate.
     *
     * Records that belong to no channel keep a thread of their own, which is the same rule: the
     * session follows the conversation, and a lone record is its own.
     */
    protected function sessionFor(Agent $agent, Model $entity): Session
    {
        $channel = $this->channelOf($entity);

        $key = $channel !== null
            ? ['entity_namespace' => $channel::class, 'entity_id' => $channel->getKey()]
            : ['entity_namespace' => $entity::class, 'entity_id' => $entity->getKey()];

        /** @var Session $session */
        $session = Session::firstOrCreate(
            [
                'apps_id' => $entity->apps_id ?? $agent->apps_id,
                'companies_id' => $entity->companies_id ?? $agent->companies_id,
                'agents_id' => $agent->getId(),
                ...$key,
            ],
            [
                'uuid' => Str::uuid()->toString(),
                'channel_id' => $channel?->getId(),
                'content' => '',
                'user' => [],
            ],
        );

        return $session;
    }

    /**
     * Whether the agent produced this record itself. Only `users_id` is consulted — it is the one
     * attribution column shared across the models a rule can fire on.
     */
    protected function isOwnWork(Agent $agent, Model $entity): bool
    {
        $author = $entity->users_id ?? null;

        return $author !== null && (int) $author === (int) $agent->user_id;
    }

    /**
     * The agent is bound to one tenant, so it is the reliable answer; the record is the fallback for
     * the case where an agent row predates that guarantee.
     */
    protected function resolveCompany(Agent $agent, Model $entity): ?Companies
    {
        $company = $agent->company;

        if ($company instanceof Companies) {
            return $company;
        }

        $fromEntity = $entity->company ?? null;

        return $fromEntity instanceof Companies ? $fromEntity : null;
    }
}
