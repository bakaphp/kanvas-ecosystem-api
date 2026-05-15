<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Actions\Chat\RunLaravelAgentChatAction;
use Kanvas\Intelligence\Agents\Actions\Chat\RunNeuronChatAction;
use Kanvas\Intelligence\Agents\Actions\Chat\RunOpenClawChatAction;
use Kanvas\Intelligence\Agents\Events\AgentChatResponseEvent;
use Kanvas\Intelligence\Agents\Laravel\KanvasLaravelAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Types\OpenClawAgentHandler;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;
use Nuwave\Lighthouse\Execution\Utils\Subscription;

class ProcessAgentChatAction
{
    public function __construct(
        protected readonly Agent $agent,
        protected readonly ?Session $session,
        protected readonly string $message,
        protected readonly Apps $app,
        protected readonly Companies $company,
        protected readonly Users $user,
        protected readonly array $images = [],
    ) {
    }

    public function execute(): string
    {
        $startTime = microtime(true);
        $sessionId = $this->session?->uuid ?? '';

        $response = $this->runHandler();

        $durationMs = (microtime(true) - $startTime) * 1000.0;
        $this->trackUsage($response, $durationMs, $sessionId);
        $this->broadcastChatResponse($sessionId, $response);

        return $response;
    }

    protected function runHandler(): string
    {
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

        if ($handlerClass === OpenClawAgentHandler::class) {
            return new RunOpenClawChatAction(
                agent: $this->agent,
                session: $this->session,
                message: $this->message,
                user: $this->user,
                images: $this->images,
            )->execute();
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

        $handler->setConfiguration($this->agent, $this->session?->entity(), null, $this->user);

        return new RunNeuronChatAction(
            agent: $this->agent,
            session: $this->session,
            message: $this->message,
            app: $this->app,
            user: $this->user,
            handler: $handler,
        )->execute();
    }

    protected function trackUsage(string $response, float $durationMs, string $sessionId): void
    {
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
            'response' => $response,
        ]);
    }
}
