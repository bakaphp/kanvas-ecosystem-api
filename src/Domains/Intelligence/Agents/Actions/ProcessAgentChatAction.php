<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
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
        if ($this->agent->type->handler === OpenClawAgentHandler::class) {
            return new RunOpenClawChatAction(
                agent: $this->agent,
                session: $this->session,
                message: $this->message,
                user: $this->user,
            )->execute();
        }

        $handler = new $this->agent->type->handler();
        $handler->setConfiguration($this->agent, $this->session?->entity());

        if ($handler instanceof KanvasLaravelAgent) {
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
