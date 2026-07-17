<?php

declare(strict_types=1);

namespace Tests\Intelligence\Sessions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Sessions\Actions\ClaimAnonymousSessionAction;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelData;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Tests\Stubs\Intelligence\ReceptionistNeuronAgentStub;
use Tests\TestCase;

class ClaimAnonymousSessionActionTest extends TestCase
{
    private function seedMessage(
        Channel $channel,
        Companies $company,
        Users $author,
        string $content,
        bool $fromIa,
        string $sessionId,
    ): void {
        $app = app(Apps::class);
        $payload = new AiChatMessagePayload(
            content: $content,
            from_me: $fromIa,
            from_ia: $fromIa,
            session_id: $sessionId,
        );

        $message = new CreateMessageAction(
            MessageInput::from([
                'app' => $app,
                'company' => $company,
                'user' => $author,
                'type' => MessageTypeService::getOrCreate($app, 'ai-chat'),
                'message' => $payload->toArray(),
                'is_public' => 1,
            ]),
            SystemModulesRepository::getByModelName(Users::class, $app),
            $author->getId(),
        )->execute();

        $channel->addMessage($message);
    }

    public function testClaimClonesAgentAndCopiesOnlyThisTokensTranscript(): void
    {
        $app = app(Apps::class);
        $newUser = auth()->user();
        $newCompany = $newUser->getCurrentCompany();

        // Demo lives in its own company (sandbox) — distinct from the signing-up user's company.
        $demoCompany = Companies::factory()->create();
        $guest = Users::factory()->create();

        $agentType = AgentType::factory()->withAppId($app->getId())->create([
            'provider' => 'neuron',
            'handler' => ReceptionistNeuronAgentStub::class,
        ]);
        $demoAgent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($demoCompany->getId())
            ->create([
                'agent_type_id' => $agentType->getId(),
                'user_id' => Users::factory()->create()->getId(),
            ]);

        $token = 'tok-claim-' . uniqid();

        $demoChannel = new CreateChannelAction(
            new ChannelData(
                apps: $app,
                companies: $demoCompany,
                users: $guest,
                entity_id: $guest->getId(),
                entity_namespace: Users::class,
                name: 'Demo chat',
                slug: 'ai-assist-demo-' . uniqid(),
            )
        )->execute();

        $demoSession = Session::create([
            'uuid' => $token,
            'canal_id' => $token,
            'apps_id' => $app->getId(),
            'companies_id' => $demoCompany->getId(),
            'agents_id' => $demoAgent->getId(),
            'channel_id' => $demoChannel->getId(),
            'entity_namespace' => Users::class,
            'entity_id' => $guest->getId(),
            'user' => ['name' => 'Guest', 'id' => $guest->getId(), 'email' => null],
            'content' => [],
        ]);

        // Two messages belong to this token; one belongs to a different visitor on the shared channel.
        $author = auth()->user();
        $this->seedMessage($demoChannel, $demoCompany, $author, 'what are your hours?', false, $token);
        $this->seedMessage($demoChannel, $demoCompany, $author, 'We are open 9-6.', true, $token);
        $this->seedMessage($demoChannel, $demoCompany, $author, 'someone-elses-chat', false, 'other-token');

        $session = new ClaimAnonymousSessionAction(
            app: $app,
            user: $newUser,
            token: $token,
        )->execute();

        $this->assertNotNull($session);

        // A clone was created in the NEW company — new id, same type.
        $this->assertNotSame($demoAgent->getId(), (int) $session->agents_id);
        $clone = Agent::getById((int) $session->agents_id, $app);
        $this->assertSame($newCompany->getId(), (int) $clone->companies_id);
        $this->assertSame($agentType->getId(), (int) $clone->agent_type_id);

        // Only this token's two messages were copied, re-tagged with the new session uuid.
        $copied = $session->channel?->messages()->get() ?? collect();
        $this->assertCount(2, $copied);
        foreach ($copied as $message) {
            $stored = (array) $message->getMessage();
            $this->assertSame($session->uuid, $stored['session_id'] ?? $stored['thread_id'] ?? null);
        }

        // Demo data is untouched — the shared channel still has all three messages.
        $this->assertCount(3, $demoChannel->fresh()->messages()->get());
        $this->assertSame(Users::class, $demoSession->fresh()->entity_namespace);
    }

    public function testReturnsNullForUnknownToken(): void
    {
        $session = new ClaimAnonymousSessionAction(
            app: app(Apps::class),
            user: auth()->user(),
            token: 'does-not-exist-' . uniqid(),
        )->execute();

        $this->assertNull($session);
    }
}
