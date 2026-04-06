<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Inspector\Configuration;
use Inspector\Inspector;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Events\AgentChatResponseEvent;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Types\ADKAgent;
use Kanvas\Intelligence\Agents\Types\OpenClawAgentHandler;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\InspectorObserver;
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

        if ($this->agent->type->handler === OpenClawAgentHandler::class) {
            $handler = new OpenClawAgentHandler();
            $handler->setAgent($this->agent);

            $response = $handler->chat($this->message);
            $durationMs = (microtime(true) - $startTime) * 1000.0;

            $this->trackUsage($response, $durationMs, $sessionId);
            $this->broadcastChatResponse($sessionId, $response);

            return $response;
        }

        $useInspector = $this->app->get('inspector-key') !== null;
        $sessionEntity = $this->session?->entity();

        $currentAgent = new $this->agent->type->handler();
        $currentAgent->setConfiguration(
            $this->agent,
            $sessionEntity
        );

        if ($useInspector) {
            $inspector = new Inspector(
                new Configuration($this->app->get('inspector-key'))
            );
            $currentAgent->observe(
                new InspectorObserver($inspector)
            );
        }

        $responseContent = $currentAgent instanceof ADKAgent ?
            $currentAgent->chatSimple(
                $this->app,
                $this->agent->company,
                (string) $this->user->getId(),
                $sessionId,
                $this->message
            ) : $currentAgent->chat(new UserMessage($this->message));

        $response = $currentAgent instanceof ADKAgent
            ? $responseContent->getContent()
            : ChatHelper::extractTextFromResponse($responseContent->getContent());
        $durationMs = (microtime(true) - $startTime) * 1000.0;

        $this->trackUsage($response, $durationMs, $sessionId);
        $this->broadcastChatResponse($sessionId, $response);

        return $response;
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
