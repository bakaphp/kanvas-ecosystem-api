<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\KanvasMessageHistory;
use Kanvas\Users\Models\Users;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\Tool;
use Tests\TestCase;

class KanvasMessageHistoryPersistTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    public function testEveryPersistedTurnCarriesStepsStatusAndParticipant(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId() + 1]);

        $sessionUuid = (string) Str::uuid();

        $history = new KanvasMessageHistory(
            app: $app,
            company: $company,
            user: $user,
            agentClass: 'Stub\\Agent',
            sessionId: $sessionUuid,
            agent: $agent,
            participant: $user,
        );

        $tool = Tool::make('get_lead', 'Look up a lead.')
            ->setCallId('c1')
            ->setInputs(['lead_id' => 7]);

        $history->addMessage(new UserMessage('status of lead 7?'));
        $history->addMessage(new ToolCallMessage(null, [$tool]));
        $history->addMessage(new ToolResultMessage([$tool->setResult(['status' => 'won'])]));
        $history->addMessage(new AssistantMessage('Lead 7 is won.'));

        // uuid7 ids written within one millisecond do not sort in insert order, so rows are picked by shape.
        $rows = DB::connection('intelligence')->table('agent_conversation_messages')
            ->where('conversation_id', $history->getConversationId())
            ->get()
            ->map(fn (object $row): object => tap($row, fn (object $r) => $r->decodedSteps = json_decode($r->steps, true)));

        $this->assertCount(4, $rows);

        $usersMorph = Relation::getMorphAlias(Users::class);
        foreach ($rows as $row) {
            $this->assertSame('completed', $row->status);
            $this->assertSame($usersMorph, $row->participant_type);
            $this->assertSame($user->getId(), (int) $row->participant_id);
            $this->assertSame($user->getId(), (int) $row->user_id);
        }

        $prompt = $rows->firstWhere('content', 'status of lead 7?');
        $this->assertSame('user', $prompt->role);
        $this->assertSame([], $prompt->decodedSteps);

        $call = $rows->first(fn (object $r): bool => $r->role === 'assistant' && json_decode($r->tool_calls, true) !== []);
        $this->assertSame('c1', $call->decodedSteps[0]['tool_calls'][0]['id']);
        $this->assertSame(['lead_id' => 7], $call->decodedSteps[0]['tool_calls'][0]['arguments']);
        $this->assertArrayNotHasKey('result', $call->decodedSteps[0]['tool_calls'][0]);

        // Neuron's ToolResultMessage is a UserMessage: the row is role=user and still carries the result.
        $result = $rows->first(fn (object $r): bool => $r->role === 'user' && json_decode($r->tool_results, true) !== []);
        $this->assertSame('', $result->decodedSteps[0]['content']);
        // Neuron's Tool::setResult() JSON-encodes a non-string result, so what is stored is that string.
        $this->assertSame('{"status":"won"}', $result->decodedSteps[0]['tool_calls'][0]['result']);

        $reply = $rows->firstWhere('content', 'Lead 7 is won.');
        $this->assertSame('Lead 7 is won.', $reply->decodedSteps[0]['content']);

        $conversation = DB::connection('intelligence')->table('agent_conversations')
            ->where('id', $history->getConversationId())
            ->first();
        $this->assertSame($usersMorph, $conversation->participant_type);
        $this->assertSame($user->getId(), (int) $conversation->participant_id);
    }
}
