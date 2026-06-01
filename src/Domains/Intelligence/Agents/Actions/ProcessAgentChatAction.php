<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Baka\Support\Str;
use Baka\Traits\LimitsBroadcastPayload;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\Chat\RunLaravelAgentChatAction;
use Kanvas\Intelligence\Agents\Actions\Chat\RunNeuronChatAction;
use Kanvas\Intelligence\Agents\Actions\Chat\RunRuntimeChatAction;
use Kanvas\Intelligence\Agents\Events\AgentChatResponseEvent;
use Kanvas\Intelligence\Agents\Exceptions\AgentProviderException;
use Kanvas\Intelligence\Agents\Laravel\KanvasLaravelAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\BaseKanvasAgent;
use Kanvas\Intelligence\Sessions\Actions\PersistChatTurnToSocialAction;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Nuwave\Lighthouse\Execution\Utils\Subscription;
use Throwable;

class ProcessAgentChatAction
{
    use LimitsBroadcastPayload;

    protected ?Message $persistedReply = null;

    /**
     * @param list<\Kanvas\Filesystem\Models\Filesystem> $attachments
     *        Freshly uploaded Filesystem records to attach to the persisted user Message.
     */
    public function __construct(
        protected readonly Agent $agent,
        protected readonly ?Session $session,
        protected readonly string $message,
        protected readonly Apps $app,
        protected readonly Companies $company,
        protected readonly Users $user,
        protected readonly array $images = [],
        protected readonly array $attachments = [],
        protected readonly ?Lead $currentLead = null,
    ) {
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
        $this->persistConversationToSocial($response);
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
            app: $this->app,
            company: $this->company,
            user: $this->user,
            userMessage: $this->message,
            assistantResponse: $response,
            images: array_values($this->images),
            attachments: $this->attachments,
            currentLead: $this->currentLead,
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
            // Pass the request-scoped app/company so tools get the correct tenant
            // context even when the agent is global (companies_id = 0 / apps_id = 0).
            $handler->setConfiguration(
                agent: $this->agent,
                entity: $this->session?->entity(),
                app: $this->app,
                company: $this->company,
            );

            return new RunLaravelAgentChatAction(
                agent: $this->agent,
                session: $this->session,
                message: $this->message,
                app: $this->app,
                company: $this->company,
                user: $this->user,
                handler: $handler,
            )->execute();
        }

        $handler->setConfiguration(
            agent: $this->agent,
            entity: $this->session?->entity(),
            user: $this->user,
        );
        $threadId = $this->session?->uuid ?? Str::uuid()->toString();

        if ($handler instanceof BaseKanvasAgent) {
            $handler->setThreadId($threadId);
            $handler->setSession($this->session);
            $handler->setCurrentLead($this->currentLead);
        }

        return new RunNeuronChatAction(
            agent: $this->agent,
            session: $this->session,
            message: $this->message,
            app: $this->app,
            user: $this->user,
            handler: $handler,
            images: $this->images
        )->execute();
    }

    protected function trackUsage(
        string $response,
        float $durationMs,
        string $sessionId
    ): void {
        new TrackAgentUsageAction(
            agent: $this->agent,
            app: $this->app,
            company: $this->company,
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
            'message' => $this->message,
            'response' => $this->limitBroadcastPayload($response),
        ]);
    }
}
