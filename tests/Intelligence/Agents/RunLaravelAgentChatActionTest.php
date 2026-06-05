<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\Chat\RunLaravelAgentChatAction;
use Kanvas\Intelligence\Agents\Laravel\KanvasLaravelAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Mockery;
use Tests\TestCase;

class RunLaravelAgentChatActionTest extends TestCase
{
    public function testPersistsToolCallsAndResultsOnTheConversationMessage(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        $response = new AgentResponse('inv-1', 'Found 2 products.', new Usage(10, 20), new Meta());
        $response->withToolCallsAndResults(
            new Collection([new ToolCall('call-1', 'InventorySearchTool', ['keyword' => 'perfume'])]),
            new Collection([new ToolResult('call-1', 'InventorySearchTool', ['keyword' => 'perfume'], ['hit'])]),
        );

        $handler = Mockery::mock(KanvasLaravelAgent::class);
        $handler->shouldReceive('promptWithConfig')->once()->andReturn($response);

        $result = new RunLaravelAgentChatAction(
            agent: $agent,
            session: null,
            message: 'Recommend a perfume',
            app: $app,
            company: $company,
            user: $user,
            handler: $handler,
        )->execute();

        $this->assertSame('Found 2 products.', $result);

        $row = DB::connection('intelligence')->table('agent_conversation_messages')
            ->where('user_id', $user->getId())
            ->where('role', 'assistant')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($row);

        $toolCalls = json_decode($row->tool_calls, true);
        $toolResults = json_decode($row->tool_results, true);
        $usage = json_decode($row->usage, true);

        $this->assertNotEmpty($toolCalls, 'tool_calls should be persisted, not an empty array');
        $this->assertSame('InventorySearchTool', $toolCalls[0]['name']);
        $this->assertSame('call-1', $toolCalls[0]['id']);

        $this->assertNotEmpty($toolResults, 'tool_results should be persisted');
        $this->assertSame('InventorySearchTool', $toolResults[0]['name']);

        $this->assertSame(10, $usage['prompt_tokens']);
        $this->assertSame(20, $usage['completion_tokens']);
    }

    public function testReturnsStructuredPayloadAsContentForStructuredAgent(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        // A HasStructuredOutput agent puts its answer in ->structured and leaves
        // ->text empty in JSON mode. The action must surface the JSON, not "".
        $structured = ['recommendations' => [['product' => ['id' => 42]]]];
        $response = new StructuredAgentResponse('inv-2', $structured, '', new Usage(1, 2), new Meta());

        $handler = Mockery::mock(KanvasLaravelAgent::class);
        $handler->shouldReceive('promptWithConfig')->once()->andReturn($response);

        $result = new RunLaravelAgentChatAction(
            agent: $agent,
            session: null,
            message: 'un regalo para mi esposo',
            app: $app,
            company: $company,
            user: $user,
            handler: $handler,
        )->execute();

        $this->assertNotSame('', $result, 'Structured agent reply must not be empty.');
        $this->assertJson($result);

        $decoded = json_decode($result, true);
        $this->assertSame(42, $decoded['recommendations'][0]['product']['id']);
    }
}
