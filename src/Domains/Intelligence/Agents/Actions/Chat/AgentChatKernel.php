<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Chat;

use Baka\Support\Str;
use Baka\Traits\LimitsBroadcastPayload;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\TrackAgentUsageAction;
use Kanvas\Intelligence\Agents\Events\AgentChatResponseEvent;
use Kanvas\Intelligence\Agents\Exceptions\AgentProviderException;
use Kanvas\Intelligence\Agents\Laravel\KanvasLaravelAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Contracts\KanvasAgent;
use Kanvas\Intelligence\Agents\Types\ADKAgent;
use Kanvas\Intelligence\Sessions\Actions\PersistChatTurnToSocialAction;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Nuwave\Lighthouse\Execution\Utils\Subscription;
use Throwable;

/**
 * Single in-process entry point for "agent answers a message." Routes on $agent
 * to Runtime (OpenClaw / Hermes), Laravel, ADK, or Neuron (default). Tenant
 * (app + company) comes from $agent — agents are bound to one tenant.
 *
 * Connector callers pass sourceChannel + sourceMessage + persistConversation=false
 * so ADK keeps its remote identity, Neuron threads by entity (cross-channel
 * rollup), and the connector owns outbound persistence.
 */
class AgentChatKernel
{
    use LimitsBroadcastPayload;

    protected ?Message $persistedReply = null;

    /**
     * @param list<string> $images Image URLs the model can take natively on every backend.
     * @param list<string> $documents Non-image native attachment URLs (audio / PDF) — sent natively
     *                                only to the in-process backends (Neuron, Laravel); the runtime
     *                                backend keeps them URL-in-prompt (it rejects non-image uploads).
     * @param list<Filesystem> $attachments Freshly uploaded files to attach to the persisted user Message.
     */
    public function __construct(
        protected readonly Agent $agent,
        protected readonly ?Session $session,
        protected readonly string $message,
        protected readonly Users $user,
        protected readonly array $images = [],
        protected readonly array $attachments = [],
        protected readonly ?Lead $currentLead = null,
        protected readonly ?Channel $sourceChannel = null,
        protected readonly ?Message $sourceMessage = null,
        protected readonly bool $persistConversation = true,
        protected readonly array $documents = [],
        protected readonly array $additionalTools = [],
    ) {
    }

    /**
     * Every attachment the in-process backends (Neuron, Laravel) can send natively: images plus the
     * audio/PDF documents. The runtime backend deliberately gets only $images.
     *
     * @return list<string>
     */
    protected function nativeMedia(): array
    {
        return array_values([...$this->images, ...$this->documents]);
    }

    public function persistedReply(): ?Message
    {
        return $this->persistedReply;
    }

    public function execute(): string
    {
        $startTime = microtime(true);
        $sessionId = $this->session?->uuid ?? '';

        try {
            $response = $this->runHandler();
        } catch (Throwable $e) {
            throw AgentProviderException::fromThrowable($e, $this->agent);
        }

        $durationMs = (microtime(true) - $startTime) * 1000.0;
        $this->trackUsage($response, $durationMs, $sessionId);

        if ($this->persistConversation) {
            $this->persistConversationToSocial($response);
        }

        $this->broadcastChatResponse($sessionId, $response);

        return $response;
    }

    /**
     * userChat's response contract promises a non-null message + channel, so failures here
     * propagate rather than getting swallowed — a half-persisted turn is a real bug to surface.
     */
    protected function persistConversationToSocial(string $response): void
    {
        if ($this->session === null) {
            return;
        }

        $this->persistedReply = new PersistChatTurnToSocialAction(
            session: $this->session,
            agent: $this->agent,
            app: $this->agent->app,
            company: $this->agent->company,
            user: $this->user,
            userMessage: $this->message,
            assistantResponse: $response,
            images: array_values($this->images),
            attachments: $this->attachments,
            currentLead: $this->currentLead,
            documents: array_values($this->documents),
        )->execute();
    }

    protected function runHandler(): string
    {
        if ($this->agent->isContainerRuntime()) {
            return new RunRuntimeChatAction(
                agent: $this->agent,
                session: $this->session,
                message: $this->message,
                user: $this->user,
                images: $this->images,
            )->execute();
        }

        $handlerClass = $this->agent->type?->handler;

        if ($handlerClass === null || $handlerClass === '' || ! class_exists($handlerClass)) {
            throw new ValidationException(sprintf(
                'Agent %d cannot run: agent_type %s has no valid handler set (got %s). '
                . 'Set agent_types.handler to a class implementing the agent runtime contract.',
                $this->agent->getId(),
                (string) ($this->agent->agent_type_id ?? 'null'),
                $handlerClass === null ? 'null' : "'{$handlerClass}'",
            ));
        }

        $handler = new $handlerClass();

        if ($handler instanceof KanvasLaravelAgent) {
            $handler->setConfiguration(
                agent: $this->agent,
                entity: $this->session?->entity(),
                app: $this->agent->app,
                company: $this->agent->company,
            );

            return new RunLaravelAgentChatAction(
                agent: $this->agent,
                session: $this->session,
                message: $this->message,
                app: $this->agent->app,
                company: $this->agent->company,
                user: $this->user,
                handler: $handler,
                media: $this->nativeMedia(),
            )->execute();
        }

        if ($handler instanceof ADKAgent) {
            return new RunADKChatAction(
                agent: $this->agent,
                session: $this->session,
                message: $this->message,
                user: $this->user,
                sourceChannel: $this->sourceChannel,
                sourceMessage: $this->sourceMessage,
            )->execute();
        }

        $handler->setConfiguration(
            agent: $this->agent,
            entity: $this->session?->entity(),
            user: $this->user,
        );

        if ($handler instanceof KanvasAgent) {
            // userChat (sourceChannel === null): scope history to this thread.
            // Channel agents: thread by entity — Lead+People IS the conversation,
            // not the per-channel session. Cross-channel rollup is the design
            // intent of SalesAssistKanvasMessageHistory.
            if ($this->sourceChannel === null) {
                $handler->setThreadId($this->session?->uuid ?? Str::uuid()->toString());
            }
            $handler->setSession($this->session);
            $handler->setCurrentLead($this->currentLead);
            // Plumb the turn's attachment URLs so the conversation history can persist a reference
            // for describing — the handler itself only ever sees the base64 content blocks.
            $handler->setTurnMedia($this->nativeMedia());
        }

        if ($this->additionalTools !== [] && $handler instanceof KanvasAgent) {
            $handler->addTool($this->additionalTools);
        }

        return new RunNeuronChatAction(
            agent: $this->agent,
            session: $this->session,
            message: $this->message,
            app: $this->agent->app,
            user: $this->user,
            handler: $handler,
            media: $this->nativeMedia()
        )->execute();
    }

    protected function trackUsage(
        string $response,
        float $durationMs,
        string $sessionId
    ): void {
        new TrackAgentUsageAction(
            agent: $this->agent,
            app: $this->agent->app,
            company: $this->agent->company,
            message: $this->message,
            response: $response,
            durationMs: $durationMs,
            sessionId: $sessionId,
            userId: $this->user->getId(),
        )->execute();
    }

    protected function broadcastChatResponse(string $sessionId, string $response): void
    {
        AgentChatResponseEvent::dispatch(
            $this->agent,
            $sessionId,
            $this->message,
            $response
        );

        Subscription::broadcast('agentChatResponse', [
            'agent_id' => $this->agent->getId(),
            'agent_name' => $this->agent->name,
            'session_id' => $sessionId,
            ...$this->limitBroadcastPayloadSet([
                'message' => $this->message,
                'response' => $response,
            ]),
        ]);
    }
}
