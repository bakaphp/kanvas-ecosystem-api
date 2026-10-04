<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Neuron;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Enums\AgentMessageTypeEnum;
use Kanvas\Intelligence\Agents\Neuron\Middleware\KanvasSummarization;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message as SocialMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use Tests\Stubs\Intelligence\SummarizingNeuronAgentStub;
use Tests\TestCase;
use Tests\Traits\MakesAgents;

/**
 * Four long turns against a 300-token budget, which is what makes the compaction run twice in a test
 * instead of needing 40K tokens of history.
 */
class SummarizationOnConversationStoreTest extends TestCase
{
    use MakesAgents;
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'social', 'crm'];

    public function testAnOvergrownConversationIsSummarizedArchivedAndWrittenToSocial(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agentRecord = $this->makeAgentFor($user);

        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $channel = Channel::firstOrCreate(
            ['apps_id' => $app->getId(), 'companies_id' => $company->getId(), 'slug' => 'summary-test-' . $agentRecord->getId()],
            ['name' => 'Summary test', 'description' => 'test', 'users_id' => $user->getId()]
        );

        $session = Session::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'channel_id' => $channel->getId(),
            'entity_namespace' => People::class,
            'entity_id' => $people->getId(),
            'uuid' => (string) Str::uuid(),
            'user' => [],
            'content' => [],
            'is_deleted' => 0,
        ]);

        $agent = new SummarizingNeuronAgentStub();
        $agent->setConfiguration(agent: $agentRecord, entity: $people, user: $user);
        $agent->setSession($session);
        $agent->setThreadId($session->uuid);

        foreach (['turn one', 'turn two', 'turn three', 'turn four'] as $turn) {
            $agent->chat(new UserMessage($turn));
        }

        $conversationId = DB::connection('intelligence')
            ->table('agent_conversations')
            ->where('title', $session->uuid)
            ->where('agent_id', $agentRecord->getId())
            ->value('id');

        $rows = DB::connection('intelligence')
            ->table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $this->assertSame(2, $agent->summaryRequests, 'The third and fourth inferences each compact');

        $archived = $rows->whereNotNull('archived_at')->values();
        $this->assertCount(4, $archived, 'The first summary is folded into the second');
        $this->assertEqualsCanonicalizing(
            ['turn one', str_repeat(SummarizingNeuronAgentStub::LONG_REPLY, 100), 'turn two'],
            $archived->filter(fn (object $row): bool => ! str_starts_with($row->content, '## Previous'))->pluck('content')->values()->all(),
        );
        $this->assertCount(10, $rows, 'Every turn stays in the transcript, both summary rows included');
        $firstSummary = $archived->first(fn (object $row): bool => str_starts_with($row->content, '## Previous'));

        $summaryRow = $rows->first(fn (object $row): bool => $row->archived_at === null && str_starts_with($row->content, '## Previous conversation summary'));
        $this->assertNotNull($summaryRow);
        $meta = json_decode($summaryRow->meta, true);
        $this->assertTrue($meta['__meta'][KanvasSummarization::SUMMARY_FLAG]);
        $this->assertStringContainsString(SummarizingNeuronAgentStub::SUMMARY_TEXT, $summaryRow->content);

        $social = SocialMessage::query()->find($meta['__meta'][KanvasSummarization::SOCIAL_MESSAGE_ID]);
        $this->assertNotNull($social, 'The summary is written to Social for humans');
        $this->assertSame(AgentMessageTypeEnum::AGENT_SUMMARY->value, $social->messageType->verb);
        $this->assertSame(0, (int) $social->is_public);
        $payload = $social->getMessage();
        $this->assertSame(SummarizingNeuronAgentStub::SUMMARY_TEXT, $payload['content']);
        $this->assertSame($firstSummary->id, $payload['from_message_id']);
        $this->assertSame($rows->firstWhere('content', 'turn two')->id, $payload['to_message_id']);
        $this->assertSame(3, $payload['archived_count']);
        $this->assertGreaterThan($payload['tokens_after'], $payload['tokens_before']);
        $this->assertTrue($channel->messages()->where('messages.id', $social->getId())->exists());

        $fourth = $agent->inferences[3];
        $this->assertStringStartsWith(
            '## Previous conversation summary',
            (string) $fourth[0]->getContent(),
            'The fourth inference opens with the summary instead of the archived turns'
        );
        $this->assertSame(
            ['user', 'assistant', 'user', 'assistant', 'user'],
            array_map(fn (Message $m): string => $m->getRole(), $fourth),
            'The kept tail keeps its place behind the summary'
        );
    }
}
