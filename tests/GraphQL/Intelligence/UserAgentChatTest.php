<?php

declare(strict_types=1);

namespace Tests\GraphQL\Intelligence;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentHistory;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Notifications\AgentReplyNotification;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Tests\Stubs\Intelligence\FakeAgentHandler;
use Tests\TestCase;

class UserAgentChatTest extends TestCase
{
    use DatabaseTransactions;

    protected Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $agentType = AgentType::factory()
            ->withAppId($app->id)
            ->create([
                'handler' => FakeAgentHandler::class,
            ]);

        $this->agent = Agent::factory()
            ->withAppId($app->id)
            ->withCompanyId($company->id)
            ->create([
                'agent_type_id' => $agentType->id,
            ]);
    }

    public function testUserChatCreatesSessionAndReturnsResponse(): void
    {
        $response = $this->graphQL('
            mutation($input: UserChatInput!) {
                aiAgentUserChat(input: $input) {
                    response
                    session_id
                }
            }
        ', [
            'input' => [
                'agent_id' => (string) $this->agent->getId(),
                'message' => 'Hello, agent!',
            ],
        ]);

        $response->assertSuccessful();
        $response->assertJsonStructure([
            'data' => [
                'aiAgentUserChat' => [
                    'response',
                    'session_id',
                ],
            ],
        ]);

        $sessionId = $response->json('data.aiAgentUserChat.session_id');
        $this->assertNotEmpty($sessionId);
        $this->assertNotEmpty($response->json('data.aiAgentUserChat.response'));
    }

    public function testUserChatWithSessionIdResumesSession(): void
    {
        $firstResponse = $this->graphQL('
            mutation($input: UserChatInput!) {
                aiAgentUserChat(input: $input) {
                    response
                    session_id
                }
            }
        ', [
            'input' => [
                'agent_id' => (string) $this->agent->getId(),
                'message' => 'Hello, agent!',
            ],
        ]);

        $firstResponse->assertSuccessful();
        $sessionId = $firstResponse->json('data.aiAgentUserChat.session_id');

        $secondResponse = $this->graphQL('
            mutation($input: UserChatInput!) {
                aiAgentUserChat(input: $input) {
                    response
                    session_id
                }
            }
        ', [
            'input' => [
                'agent_id' => (string) $this->agent->getId(),
                'message' => 'Follow-up message',
                'session_id' => $sessionId,
            ],
        ]);

        $secondResponse->assertSuccessful();
        $this->assertEquals($sessionId, $secondResponse->json('data.aiAgentUserChat.session_id'));
    }

    public function testUserChatWithUnknownSessionIdCreatesNewSession(): void
    {
        $unknownSessionId = (string) Str::uuid();

        $response = $this->graphQL('
            mutation($input: UserChatInput!) {
                aiAgentUserChat(input: $input) {
                    response
                    session_id
                }
            }
        ', [
            'input' => [
                'agent_id' => (string) $this->agent->getId(),
                'message' => 'Hello with a stale session id',
                'session_id' => $unknownSessionId,
            ],
        ]);

        $response->assertSuccessful();
        $this->assertArrayNotHasKey('errors', $response->json(), 'GraphQL errors: ' . $response->getContent());

        $newSessionId = $response->json('data.aiAgentUserChat.session_id');
        $this->assertNotEmpty($newSessionId);
        $this->assertNotEquals($unknownSessionId, $newSessionId);

        $session = Session::where('uuid', $newSessionId)->first();
        $this->assertNotNull($session);
        $this->assertEquals(Users::class, $session->entity_namespace);
    }

    public function testUserChatWithoutSessionIdAlwaysCreatesNewSession(): void
    {
        $firstResponse = $this->graphQL('
            mutation($input: UserChatInput!) {
                aiAgentUserChat(input: $input) {
                    response
                    session_id
                }
            }
        ', [
            'input' => [
                'agent_id' => (string) $this->agent->getId(),
                'message' => 'First message',
            ],
        ]);

        $firstResponse->assertSuccessful();
        $firstSessionId = $firstResponse->json('data.aiAgentUserChat.session_id');

        $secondResponse = $this->graphQL('
            mutation($input: UserChatInput!) {
                aiAgentUserChat(input: $input) {
                    response
                    session_id
                }
            }
        ', [
            'input' => [
                'agent_id' => (string) $this->agent->getId(),
                'message' => 'Second message',
            ],
        ]);

        $secondResponse->assertSuccessful();
        $secondSessionId = $secondResponse->json('data.aiAgentUserChat.session_id');

        $this->assertNotEquals($firstSessionId, $secondSessionId);
    }

    public function testUserChatWithLeadId(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppId($app->id)
            ->withCompanyId($company->id)
            ->create();

        $response = $this->graphQL('
            mutation($input: UserChatInput!) {
                aiAgentUserChat(input: $input) {
                    response
                    session_id
                }
            }
        ', [
            'input' => [
                'agent_id' => (string) $this->agent->getId(),
                'message' => 'Hello from lead context',
                'lead_id' => (string) $lead->getId(),
            ],
        ]);

        $response->assertSuccessful();
        $this->assertArrayNotHasKey('errors', $response->json(), 'GraphQL errors: ' . $response->getContent());

        $sessionId = $response->json('data.aiAgentUserChat.session_id');
        $this->assertNotEmpty($sessionId);

        $session = Session::where('uuid', $sessionId)->first();
        $this->assertNotNull($session);
        // Post PR3 (People-as-session-entity): the session is People-keyed,
        // not Lead-keyed. The current Lead is plumbed per-turn from the input.
        $this->assertEquals(People::class, $session->entity_namespace);
        $this->assertEquals($lead->people->getId(), $session->entity_id);
    }

    public function testUserChatResponseSurfacesPersistedMessageAndChannel(): void
    {
        $response = $this->graphQL('
            mutation($input: UserChatInput!) {
                aiAgentUserChat(input: $input) {
                    response
                    session_id
                    message {
                        id
                        uuid
                        message
                        total_liked
                        total_saved
                        total_shared
                        reactions_count
                    }
                    channel {
                        id
                        uuid
                        slug
                        last_message_id
                    }
                }
            }
        ', [
            'input' => [
                'agent_id' => (string) $this->agent->getId(),
                'message' => 'expose me to social',
            ],
        ]);

        $response->assertSuccessful();
        $this->assertArrayNotHasKey('errors', $response->json(), 'GraphQL errors: ' . $response->getContent());

        $sessionId = $response->json('data.aiAgentUserChat.session_id');
        $messageNode = $response->json('data.aiAgentUserChat.message');
        $channelNode = $response->json('data.aiAgentUserChat.channel');

        $this->assertNotNull($messageNode, 'Response must carry the persisted assistant Message');
        $this->assertNotNull($channelNode, 'Response must carry the persisted Channel');

        $this->assertNotNull($messageNode['id']);
        $this->assertNotNull($messageNode['uuid']);
        $this->assertSame('This is a fake agent response.', $messageNode['message']['content']);
        $this->assertTrue($messageNode['message']['from_ia']);
        $this->assertSame($sessionId, $messageNode['message']['session_id']);
        $this->assertEquals($this->agent->getId(), $messageNode['message']['agent_id']);

        // The Social engagement surface is now reachable straight off the chat response —
        // proves the chat plug into the Social graph is wired without an extra round-trip.
        $this->assertSame(0, $messageNode['total_liked']);
        $this->assertSame(0, $messageNode['total_saved']);
        $this->assertSame(0, $messageNode['total_shared']);
        $this->assertSame(0, $messageNode['reactions_count']);

        $this->assertNotNull($channelNode['id']);
        $this->assertNotNull($channelNode['uuid']);
        $this->assertStringStartsWith(
            'ai-assist-' . $this->agent->getId() . '-',
            $channelNode['slug'],
            'User-session channel must be the ai-assist channel keyed per (agent, user)',
        );
        $this->assertEquals($messageNode['id'], $channelNode['last_message_id'], 'Channel.last_message_id should point at the assistant reply we just persisted');

        $session = Session::where('uuid', $sessionId)->first();
        $this->assertEquals(
            $session->channel_id,
            $channelNode['id'],
            'Response channel matches the session\'s channel',
        );

        $aiMessage = Channel::find($channelNode['id'])
            ->messages()
            ->orderByDesc('messages.id')
            ->first();
        $this->assertEquals(
            $aiMessage->getId(),
            $messageNode['id'],
            'Response message is the assistant reply on the channel',
        );
    }

    public function testUserChatKeepsEveryTurnOnTheSameChannel(): void
    {
        $prompts = ['turn one prompt', 'turn two prompt', 'turn three prompt'];
        $sessionId = null;
        $channelIds = [];

        foreach ($prompts as $prompt) {
            $input = [
                'agent_id' => (string) $this->agent->getId(),
                'message' => $prompt,
            ];
            if ($sessionId !== null) {
                $input['session_id'] = $sessionId;
            }

            $turn = $this->graphQL('
                mutation($input: UserChatInput!) {
                    aiAgentUserChat(input: $input) {
                        session_id
                        channel { id slug }
                        message { id }
                    }
                }
            ', ['input' => $input]);

            $turn->assertSuccessful();
            $this->assertArrayNotHasKey('errors', $turn->json(), 'GraphQL errors: ' . $turn->getContent());

            $sessionId = $turn->json('data.aiAgentUserChat.session_id');
            $channelIds[] = $turn->json('data.aiAgentUserChat.channel.id');
        }

        $this->assertCount(1, array_unique($channelIds), 'All turns must land on the same channel');

        $channel = Channel::find($channelIds[0]);
        $this->assertCount(
            count($prompts) * 2,
            $channel->messages()->get(),
            'Channel should hold one user prompt + one assistant reply per turn',
        );

        // Order check: assistant replies should match the order prompts were sent.
        $assistantContents = $channel->messages()
            ->orderBy('messages.id')
            ->get()
            ->filter(fn (Message $m): bool => (bool) ($m->getMessage()['from_ia'] ?? false))
            ->map(fn (Message $m): string => (string) ($m->getMessage()['content'] ?? ''))
            ->values()
            ->all();

        $this->assertSame(
            array_fill(0, count($prompts), 'This is a fake agent response.'),
            $assistantContents,
            'Assistant replies must be present once per turn, in order',
        );
    }

    public function testUserChatMirrorsLeadConversationToLeadChannelAndMarksResponded(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppId($app->id)
            ->withCompanyId($company->id)
            ->create();

        $response = $this->graphQL('
            mutation($input: UserChatInput!) {
                aiAgentUserChat(input: $input) {
                    response
                    session_id
                }
            }
        ', [
            'input' => [
                'agent_id' => (string) $this->agent->getId(),
                'message' => 'Hello from lead context',
                'lead_id' => (string) $lead->getId(),
            ],
        ]);

        $response->assertSuccessful();
        $this->assertArrayNotHasKey('errors', $response->json(), 'GraphQL errors: ' . $response->getContent());

        $sessionId = $response->json('data.aiAgentUserChat.session_id');
        $session = Session::where('uuid', $sessionId)->first();
        $channel = Channel::find($session->channel_id);
        // Post PR3 (People-as-session-entity): the session's channel is the
        // People channel created by PeopleChannelService, not the per-lead one.
        $this->assertNotNull($channel, 'People session should land on the durable People channel');

        $messages = $channel->messages()->get();
        $this->assertCount(2, $messages, 'People channel should receive user prompt + assistant reply');

        foreach ($messages as $message) {
            $this->assertSame($sessionId, $message->getMessage()['session_id']);
            $this->assertEquals($this->agent->getId(), $message->getMessage()['agent_id']);
            $entity = $message->entity();
            $this->assertInstanceOf(People::class, $entity, 'People-channel messages must be linked to the People entity');
            $this->assertEquals($lead->people->getId(), $entity->getId());
        }

        $aiMessage = $messages->first(fn (Message $m): bool => (bool) ($m->getMessage()['from_ia'] ?? false));
        $userMessage = $messages->first(fn (Message $m): bool => ! ($m->getMessage()['from_ia'] ?? false));
        $this->assertNotNull($aiMessage);
        $this->assertNotNull($userMessage);

        $aiMessage->refresh();
        $userMessage->refresh();

        if ($this->agent->user) {
            $this->assertEquals(
                $this->agent->user->getId(),
                $aiMessage->users_id,
                'Assistant reply must be authored by the agent\'s own user',
            );
        }

        $this->assertTrue(
            (bool) $userMessage->is_un_response,
            'MarkLeadMessagesAsRespondedAction should flip the user prompt to responded',
        );
        $this->assertEquals(
            $aiMessage->getId(),
            $userMessage->response_message_id,
            'User prompt should point at the assistant reply as its response',
        );
    }

    public function testUserChatWithoutLeadIdCreatesUserSession(): void
    {
        $response = $this->graphQL('
            mutation($input: UserChatInput!) {
                aiAgentUserChat(input: $input) {
                    response
                    session_id
                }
            }
        ', [
            'input' => [
                'agent_id' => (string) $this->agent->getId(),
                'message' => 'Hello from user context',
            ],
        ]);

        $response->assertSuccessful();

        $sessionId = $response->json('data.aiAgentUserChat.session_id');
        $session = Session::where('uuid', $sessionId)->first();

        $this->assertNotNull($session);
        $this->assertEquals(Users::class, $session->entity_namespace);
    }

    public function testUserChatMirrorsConversationToSocialChannel(): void
    {
        $response = $this->graphQL('
            mutation($input: UserChatInput!) {
                aiAgentUserChat(input: $input) {
                    response
                    session_id
                }
            }
        ', [
            'input' => [
                'agent_id' => (string) $this->agent->getId(),
                'message' => 'Persist me to social',
            ],
        ]);

        $response->assertSuccessful();
        $sessionId = $response->json('data.aiAgentUserChat.session_id');

        $session = Session::where('uuid', $sessionId)->first();
        $this->assertNotNull($session->channel_id, 'Session should be linked to a Social channel');

        $channel = Channel::find($session->channel_id);
        $this->assertNotNull($channel);

        $messages = $channel->messages()->get();
        $this->assertCount(2, $messages, 'One user message + one assistant message');

        $userMessage = $messages->first(fn (Message $m): bool => ! ($m->getMessage()['from_ia'] ?? false));
        $aiMessage = $messages->first(fn (Message $m): bool => (bool) ($m->getMessage()['from_ia'] ?? false));

        $this->assertNotNull($userMessage);
        $this->assertNotNull($aiMessage);

        $this->assertSame('Persist me to social', $userMessage->getMessage()['content']);
        $this->assertSame('This is a fake agent response.', $aiMessage->getMessage()['content']);

        $this->assertSame($sessionId, $userMessage->getMessage()['session_id']);
        $this->assertSame($sessionId, $aiMessage->getMessage()['session_id']);

        $this->assertEquals($userMessage->getId(), $aiMessage->parent_id, 'Assistant reply threads under the user message');
        $this->assertEquals($userMessage->getId(), $aiMessage->response_message_id);
    }

    public function testUserChatReusesSameChannelAcrossTurns(): void
    {
        $first = $this->graphQL('
            mutation($input: UserChatInput!) {
                aiAgentUserChat(input: $input) { response session_id }
            }
        ', [
            'input' => [
                'agent_id' => (string) $this->agent->getId(),
                'message' => 'Turn one',
            ],
        ]);
        $first->assertSuccessful();
        $sessionId = $first->json('data.aiAgentUserChat.session_id');

        $second = $this->graphQL('
            mutation($input: UserChatInput!) {
                aiAgentUserChat(input: $input) { response session_id }
            }
        ', [
            'input' => [
                'agent_id' => (string) $this->agent->getId(),
                'message' => 'Turn two',
                'session_id' => $sessionId,
            ],
        ]);
        $second->assertSuccessful();
        $this->assertEquals($sessionId, $second->json('data.aiAgentUserChat.session_id'));

        $session = Session::where('uuid', $sessionId)->first();
        $channel = Channel::find($session->channel_id);

        $this->assertCount(4, $channel->messages()->get(), 'Two turns append to one channel');
    }

    public function testAgentHistoryTracksUserId(): void
    {
        $user = auth()->user();

        $response = $this->graphQL('
            mutation($input: UserChatInput!) {
                aiAgentUserChat(input: $input) {
                    response
                    session_id
                }
            }
        ', [
            'input' => [
                'agent_id' => (string) $this->agent->getId(),
                'message' => 'Track my user ID',
            ],
        ]);

        $response->assertSuccessful();

        $history = AgentHistory::where('agent_id', $this->agent->getId())
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($history);
        $this->assertEquals($user->getId(), $history->users_id);
    }

    public function testUserChatSendsAgentReplyNotificationToUser(): void
    {
        Notification::fake();
        $user = auth()->user();

        $response = $this->graphQL('
            mutation($input: UserChatInput!) {
                aiAgentUserChat(input: $input) {
                    response
                    session_id
                }
            }
        ', [
            'input' => [
                'agent_id' => (string) $this->agent->getId(),
                'message' => 'Ping me when you reply',
            ],
        ]);

        $response->assertSuccessful();
        $this->assertArrayNotHasKey('errors', $response->json(), 'GraphQL errors: ' . $response->getContent());

        Notification::assertSentTo(
            $user,
            AgentReplyNotification::class,
            function (AgentReplyNotification $notification) use ($user): bool {
                return in_array('push', $notification->channels, true)
                    && in_array('expo', $notification->channels, true)
                    && $notification->toOneSignal($user)['message'] === 'This is a fake agent response.';
            }
        );
    }

    public function testExistingLeadFlowUnchanged(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppId($app->id)
            ->withCompanyId($company->id)
            ->create();

        $createSessionResponse = $this->graphQL('
            mutation($input: AgentSessionInput!) {
                aiAgentCreateSession(input: $input)
            }
        ', [
            'input' => [
                'agent_id' => (string) $this->agent->getId(),
                'canal_id' => 'test-canal',
                'lead_id' => (string) $lead->getId(),
            ],
        ]);

        $createSessionResponse->assertSuccessful();
        $this->assertArrayNotHasKey('errors', $createSessionResponse->json(), 'GraphQL errors: ' . $createSessionResponse->getContent());
        $sessionId = $createSessionResponse->json('data.aiAgentCreateSession');
        $this->assertNotEmpty($sessionId);

        $chatResponse = $this->graphQL('
            mutation($input: ChatSimpleInput!) {
                aiAgentChat(input: $input)
            }
        ', [
            'input' => [
                'agent_id' => (string) $this->agent->getId(),
                'session_id' => $sessionId,
                'message' => 'Hello via legacy flow',
            ],
        ]);

        $chatResponse->assertSuccessful();
        $this->assertNotEmpty($chatResponse->json('data.aiAgentChat'));
    }
}
