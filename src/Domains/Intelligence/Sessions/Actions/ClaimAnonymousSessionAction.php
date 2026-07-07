<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Actions;

use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Actions\CloneAgentForCompanyAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\DataTransferObject\AiChatMessagePayload;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelData;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;

/**
 * Carry a demo conversation into a signed-up user's account: clone the demo agent into the user's
 * company and copy the token's transcript as the clone's seed history. The demo session/lead/messages
 * stay in the demo company untouched — this reads them as a source, it never moves them.
 */
final class ClaimAnonymousSessionAction
{
    private const string MESSAGE_VERB = 'ai-chat';

    public function __construct(
        private readonly Apps $app,
        private readonly Users $user,
        private readonly string $token,
    ) {
    }

    public function execute(): ?Session
    {
        /** @var Companies $newCompany */
        $newCompany = $this->user->getCurrentCompany();

        $demoSession = Session::query()
            ->fromApp($this->app)
            ->where('uuid', $this->token)
            ->first();

        if ($demoSession === null || $demoSession->agent === null) {
            return null;
        }

        $clone = new CloneAgentForCompanyAction(
            source: $demoSession->agent,
            app: $this->app,
            company: $newCompany,
            owner: $this->user,
        )->execute();

        $channel = new CreateChannelAction(
            new ChannelData(
                apps: $this->app,
                companies: $newCompany,
                users: $this->user,
                entity_id: $this->user->getId(),
                entity_namespace: Users::class,
                name: 'AI chat with ' . $clone->name,
                description: 'Continued from the landing-page demo.',
                slug: 'ai-assist-' . (int) $clone->getId() . '-' . $this->user->getId() . '-' . Str::random(6),
            )
        )->execute();

        $newSession = Session::create([
            'uuid' => (string) Str::uuid(),
            'canal_id' => $this->token,
            'apps_id' => $this->app->getId(),
            'companies_id' => $newCompany->getId(),
            'agents_id' => $clone->getId(),
            'channel_id' => $channel->getId(),
            'entity_namespace' => Users::class,
            'entity_id' => $this->user->getId(),
            'user' => ['name' => $this->user->displayname, 'id' => $this->user->getId(), 'email' => $this->user->email],
            'content' => [],
        ]);

        $this->copyTranscript($demoSession, $newSession, $clone, $newCompany);

        return $newSession;
    }

    private function copyTranscript(
        Session $demoSession,
        Session $newSession,
        Agent $clone,
        Companies $newCompany,
    ): void {
        $channel = $newSession->channel;
        $demoChannel = $demoSession->channel;
        if ($channel === null || $demoChannel === null) {
            return;
        }

        $type = MessageTypeService::getOrCreate($this->app, self::MESSAGE_VERB);
        $systemModule = SystemModulesRepository::getByModelName(Users::class, $this->app);
        $agentUser = $clone->user ?? $this->user;

        foreach ($demoChannel->messages()->orderBy('id')->get() as $message) {
            $stored = (array) $message->getMessage();
            $storedSessionId = $stored['thread_id'] ?? $stored['session_id'] ?? null;
            if ($storedSessionId !== $demoSession->uuid) {
                continue;
            }

            $fromIa = (bool) ($stored['from_ia'] ?? false);
            $payload = new AiChatMessagePayload(
                content: (string) ($stored['content'] ?? ''),
                from_me: $fromIa,
                from_ia: $fromIa,
                session_id: $newSession->uuid,
                agent_id: (int) $clone->getId(),
            );

            $copy = new CreateMessageAction(
                MessageInput::from([
                    'app' => $this->app,
                    'company' => $newCompany,
                    'user' => $fromIa ? $agentUser : $this->user,
                    'type' => $type,
                    'message' => $payload->toArray(),
                    'is_public' => 1,
                ]),
                $systemModule,
                $this->user->getId(),
            )->execute();

            $channel->addMessage($copy);
        }
    }
}
