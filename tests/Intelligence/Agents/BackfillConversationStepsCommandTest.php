<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class BackfillConversationStepsCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'crm'];

    public function testRewritesOldRowsIntoStepsAndKeysEveryConversationToItsParticipant(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $sessionUuid = (string) Str::uuid();
        Session::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'agents_id' => $agent->getId(),
            'uuid' => $sessionUuid,
            'canal_id' => '',
            'entity_namespace' => People::class,
            'entity_id' => $people->getId(),
            'user' => [],
            'content' => [],
        ]);

        $userConversation = $this->seedConversation($agent, userId: $user->getId(), title: 'a user chat');
        $hermesConversation = $this->seedConversation($agent, userId: null, title: 'hermes-run-77');
        $publicConversation = $this->seedConversation($agent, userId: $user->getId(), title: $sessionUuid);

        $this->seedMessage($userConversation, 'user', 'Do you have it?');
        $laravelTurn = $this->seedMessage(
            $userConversation,
            'assistant',
            'Yes, 3 in stock.',
            toolCalls: [['id' => 'call_1', 'name' => 'search_products', 'arguments' => ['q' => 'hoodie']]],
            toolResults: [['id' => 'call_1', 'name' => 'search_products', 'arguments' => ['q' => 'hoodie'], 'result' => '[{"stock":3}]']],
            meta: ['provider' => 'gemini', 'reasoning' => 'Need stock first.', 'provider_steps' => [['raw' => true]]],
        );
        $hermesCall = $this->seedMessage(
            $hermesConversation,
            'assistant',
            '',
            toolCalls: [['id' => 't9', 'name' => 'read_file', 'arguments' => ['path' => 'a.php']]],
        );
        $hermesResult = $this->seedMessage(
            $hermesConversation,
            'tool_result',
            '',
            toolResults: [['id' => 't9', 'name' => 'read_file', 'arguments' => null, 'result' => '<?php', 'result_id' => 't9']],
        );
        $publicTurn = $this->seedMessage($publicConversation, 'assistant', 'Welcome to the store.');

        // A staff member's chat keyed to the same People session: the acting user is a human, so the
        // session's Person must NOT claim it.
        $humanUserId = $user->getId() + 777_000;
        $staffConversation = $this->seedConversation($agent, userId: $humanUserId, title: $sessionUuid);

        // The same uuid with a stale People session beside a newer Users one: the newest row is the live
        // session, so the Person must not claim an AI-run conversation on that uuid either.
        $staleUuid = (string) Str::uuid();
        foreach ([[People::class, $people->getId()], [Users::class, $user->getId()]] as [$namespace, $entityId]) {
            Session::create([
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'agents_id' => $agent->getId(),
                'uuid' => $staleUuid,
                'canal_id' => '',
                'entity_namespace' => $namespace,
                'entity_id' => $entityId,
                'user' => [],
                'content' => [],
            ]);
        }
        $staleConversation = $this->seedConversation($agent, userId: $user->getId(), title: $staleUuid);

        $this->artisan('agents:backfill-conversation-steps', ['--chunk' => 2])->assertExitCode(0);

        $staleRow = $this->conversation($staleConversation);
        $this->assertSame(Relation::getMorphAlias(Users::class), $staleRow->participant_type, 'the newest session row decides, not a stale People one');

        $staffRow = $this->conversation($staffConversation);
        $this->assertSame(Relation::getMorphAlias(Users::class), $staffRow->participant_type);
        $this->assertSame($humanUserId, (int) $staffRow->participant_id);

        $laravel = $this->message($laravelTurn);
        $steps = json_decode($laravel->steps, true);
        $this->assertCount(2, $steps);
        $this->assertSame('[{"stock":3}]', $steps[0]['tool_calls'][0]['result']);
        $this->assertSame('Yes, 3 in stock.', $steps[1]['content']);
        $this->assertSame('Need stock first.', $steps[1]['reasoning']);
        $this->assertSame(['provider' => 'gemini'], json_decode($laravel->meta, true));
        $this->assertSame('completed', $laravel->status);

        $this->assertSame([], json_decode($this->message($hermesCall)->steps, true)[0]['tool_calls'][0]['arguments'] === ['path' => 'a.php'] ? [] : ['unexpected arguments']);
        $this->assertArrayNotHasKey('result', json_decode($this->message($hermesCall)->steps, true)[0]['tool_calls'][0]);
        $this->assertSame('<?php', json_decode($this->message($hermesResult)->steps, true)[0]['tool_calls'][0]['result']);

        $this->assertSame('[]', $this->message($this->firstMessageId($userConversation, 'user'))->steps);

        $usersMorph = Relation::getMorphAlias(Users::class);
        $userRow = $this->conversation($userConversation);
        $this->assertSame($usersMorph, $userRow->participant_type);
        $this->assertSame($user->getId(), (int) $userRow->participant_id);

        $hermesRow = $this->conversation($hermesConversation);
        $this->assertSame(Relation::getMorphAlias(Agent::class), $hermesRow->participant_type);
        $this->assertSame($agent->getId(), (int) $hermesRow->participant_id);
        $this->assertSame($user->getId(), (int) $hermesRow->user_id, 'an agent-owned conversation acts through the agent\'s dedicated user');

        $publicRow = $this->conversation($publicConversation);
        $this->assertSame(Relation::getMorphAlias(People::class), $publicRow->participant_type);
        $this->assertSame($people->getId(), (int) $publicRow->participant_id);
        $this->assertSame($user->getId(), (int) $publicRow->user_id, 'the acting user of a public chat stays');

        $hermesMessage = $this->message($hermesCall);
        $this->assertSame(Relation::getMorphAlias(Agent::class), $hermesMessage->participant_type);
        $this->assertSame($user->getId(), (int) $hermesMessage->user_id, 'message user_id is filled from the conversation');
        $this->assertSame(Relation::getMorphAlias(People::class), $this->message($publicTurn)->participant_type);
    }

    public function testASecondRunTouchesNothing(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        $conversation = $this->seedConversation($agent, userId: $user->getId(), title: 'idempotent');
        $messageId = $this->seedMessage($conversation, 'assistant', 'once');

        $this->artisan('agents:backfill-conversation-steps')->assertExitCode(0);
        $before = $this->message($messageId);

        $this->artisan('agents:backfill-conversation-steps')
            ->expectsTable(['Backfill', 'Rows'], [
                ['message steps', 0],
                ['People conversations', 0],
                ['Agent conversations', 0],
                ['Users conversations', 0],
                ['message participants', 0],
            ])
            ->assertExitCode(0);

        $this->assertEquals($before, $this->message($messageId));
    }

    private function seedConversation(Agent $agent, ?int $userId, string $title): string
    {
        $id = (string) Str::uuid7();

        DB::connection('intelligence')->table('agent_conversations')->insert([
            'id' => $id,
            'user_id' => $userId,
            'agent_id' => $agent->getId(),
            'apps_id' => $agent->apps_id,
            'companies_id' => $agent->companies_id,
            'title' => $title,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * @param array<int, mixed> $toolCalls
     * @param array<int, mixed> $toolResults
     * @param array<string, mixed> $meta
     */
    private function seedMessage(
        string $conversationId,
        string $role,
        string $content,
        array $toolCalls = [],
        array $toolResults = [],
        array $meta = [],
    ): string {
        $id = (string) Str::uuid7();

        DB::connection('intelligence')->table('agent_conversation_messages')->insert([
            'id' => $id,
            'conversation_id' => $conversationId,
            'user_id' => null,
            'agent' => 'Legacy\\Agent',
            'role' => $role,
            'content' => $content,
            'attachments' => '[]',
            'tool_calls' => json_encode($toolCalls),
            'tool_results' => json_encode($toolResults),
            'usage' => '[]',
            'meta' => json_encode($meta),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function firstMessageId(string $conversationId, string $role): string
    {
        return (string) DB::connection('intelligence')->table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->where('role', $role)
            ->orderBy('id')
            ->value('id');
    }

    private function message(string $id): object
    {
        return DB::connection('intelligence')->table('agent_conversation_messages')->where('id', $id)->first();
    }

    private function conversation(string $id): object
    {
        return DB::connection('intelligence')->table('agent_conversations')->where('id', $id)->first();
    }
}
