<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Inspector\Configuration;
use Inspector\Inspector;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\TrackAgentUsageAction;
use Kanvas\Intelligence\Agents\Events\AgentChatResponseEvent;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Types\ADKAgent;
use Kanvas\Intelligence\Agents\Types\OpenClawAgentHandler;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session as DataTransferObjectSession;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\InspectorObserver;
use Nuwave\Lighthouse\Execution\Utils\Subscription;

class AgentChatMutation
{
    public function chat(mixed $root, array $req): string
    {
        /** @var array<string, mixed> $input */
        $input = $req['input'] ?? [];
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp(
            id: $input['agent_id'],
            app: $app,
            company: $company
        );

        $message = (string) $input['message'];
        $sessionId = (string) $input['session_id'];
        $startTime = microtime(true);

        if ($agent->type->handler === OpenClawAgentHandler::class) {
            $handler = new OpenClawAgentHandler();
            $handler->setAgent($agent);

            $response = $handler->chat($message);
            $durationMs = (microtime(true) - $startTime) * 1000.0;

            $this->trackUsage($agent, $app, $company, $message, $response, $durationMs, $sessionId);
            $this->broadcastChatResponse($agent, $sessionId, $message, $response);

            return $response;
        }

        $useInspector = $app->get('inspector-key') !== null;

        $currentAgent = new $agent->type->handler();
        $sessionEntity = Session::fromApp($app)->fromCompany($company)->where('uuid', $sessionId)->first()?->entity();

        $currentAgent->setConfiguration(
            $agent,
            $sessionEntity
        );

        if ($useInspector) {
            $inspector = new Inspector(
                new Configuration($app->get('inspector-key'))
            );
            $currentAgent->observe(
                new InspectorObserver($inspector)
            );
        }

        $responseContent = $currentAgent instanceof ADKAgent ?
        $currentAgent->chatSimple(
            $app,
            $agent->company,
            (string) auth()->user()->getId(),
            $sessionId,
            $message
        ) : $currentAgent->chat(new UserMessage($message));

        $response = ChatHelper::extractTextFromResponse($responseContent->getContent());
        $durationMs = (microtime(true) - $startTime) * 1000.0;

        $this->trackUsage($agent, $app, $company, $message, $response, $durationMs, $sessionId);
        $this->broadcastChatResponse($agent, $sessionId, $message, $response);

        return $response;
    }

    public function createSession(mixed $root, array $req): string
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $input = $req['input'] ?? [];
        $agent = Agent::getByIdFromCompanyApp(
            id: $input['agent_id'],
            app: $app,
            company: $company
        );

        $lead = Lead::getByIdFromCompanyApp(
            id: $input['lead_id'],
            app: $app,
            company: $company
        );

        $channelName = 'Manual Channel for Lead ' . $lead->getId();
        $slug = Str::simpleSlug($channelName);

        $channel = new CreateChannelAction(
            new ChannelDto(
                apps: $app,
                companies: $company,
                users: $user,
                name: $channelName,
                description: 'Channel for lead ' . $lead->getId(),
                entity_id: $lead->getId(),
                entity_namespace: Lead::class,
                slug: $slug,
            )
        )->execute();

        $chatSession = new CreateSessionAction(
            DataTransferObjectSession::from([
                'app' => $app,
                'company' => $company,
                'channel' => $channel,
                'entity_namespace' => Lead::class,
                'entity_id' => $lead->getId(),
                'canal_id' => $input['canal_id'],
                'user' => [
                    'name' => $lead->people->getName(),
                    'id' => $lead->people->getId(),
                    'email' => $lead->people->getEmails()->first()?->value,
                ],
                'agent' => $agent,
            ])
        )->execute();

        return $chatSession->uuid;
    }

    protected function trackUsage(
        Agent $agent,
        AppInterface $app,
        CompanyInterface $company,
        string $message,
        string $response,
        float $durationMs,
        string $sessionId,
    ): void {
        new TrackAgentUsageAction(
            agent: $agent,
            app: $app,
            company: $company,
            message: $message,
            response: $response,
            durationMs: $durationMs,
            sessionId: $sessionId,
        )->execute();
    }

    protected function broadcastChatResponse(
        Agent $agent,
        string $sessionId,
        string $message,
        string $response,
    ): void {
        AgentChatResponseEvent::dispatch($agent, $sessionId, $message, $response);

        Subscription::broadcast('agentChatResponse', [
            'agent_id' => $agent->getId(),
            'agent_name' => $agent->name,
            'session_id' => $sessionId,
            'message' => $message,
            'response' => $response,
        ]);
    }
}
