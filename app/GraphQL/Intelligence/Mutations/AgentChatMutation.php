<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\ProcessAgentChatAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\Actions\CreateUserSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session as DataTransferObjectSession;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;

class AgentChatMutation
{
    public function chat(mixed $root, array $req): string
    {
        /** @var array<string, mixed> $input */
        $input = $req['input'] ?? [];
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp(
            id: $input['agent_id'],
            app: $app,
            company: $company
        );

        $sessionId = (string) $input['session_id'];
        $session = Session::fromApp($app)->fromCompany($company)->where('uuid', $sessionId)->first();

        return new ProcessAgentChatAction(
            agent: $agent,
            session: $session,
            message: (string) $input['message'],
            app: $app,
            company: $company,
            user: $user,
        )->execute();
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

    public function userChat(mixed $root, array $req): array
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

        if (! empty($input['session_id'])) {
            $session = Session::fromApp($app)
                ->fromCompany($company)
                ->where('uuid', (string) $input['session_id'])
                ->firstOrFail();
        } elseif (! empty($input['lead_id'])) {
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

            $session = new CreateSessionAction(
                DataTransferObjectSession::from([
                    'app' => $app,
                    'company' => $company,
                    'channel' => $channel,
                    'entity_namespace' => Lead::class,
                    'entity_id' => $lead->getId(),
                    'canal_id' => $agent->getId(),
                    'user' => [
                        'name' => $lead->people->getName(),
                        'id' => $lead->people->getId(),
                        'email' => $lead->people->getEmails()->first()?->value,
                    ],
                    'agent' => $agent,
                ])
            )->execute();
        } else {
            $session = new CreateUserSessionAction(
                agent: $agent,
                user: $user,
                app: $app,
                company: $company,
            )->execute();
        }

        $response = new ProcessAgentChatAction(
            agent: $agent,
            session: $session,
            message: (string) $input['message'],
            app: $app,
            company: $company,
            user: $user,
        )->execute();

        return [
            'response' => $response,
            'session_id' => $session->uuid,
        ];
    }
}
