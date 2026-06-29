<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\Chat\RunLaravelAgentChatAction;
use Kanvas\Intelligence\Agents\Enums\CaptionTargetEnum;
use Kanvas\Intelligence\Agents\Jobs\DescribeMessageAttachmentsJob;
use Kanvas\Intelligence\Agents\Laravel\KanvasLaravelAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
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

    public function testForwardsImagesToTheModelAsAttachments(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        $imagePath = $this->writeTempPng();
        $response = new AgentResponse('inv-3', 'I see a 1x1 image.', new Usage(1, 1), new Meta());

        $handler = Mockery::mock(KanvasLaravelAgent::class);
        $handler->shouldReceive('promptWithConfig')
            ->once()
            ->with(
                'what is this?',
                Mockery::on(
                    static fn (array $attachments): bool => count($attachments) === 1 && $attachments[0] instanceof Base64Image,
                ),
            )
            ->andReturn($response);

        $result = new RunLaravelAgentChatAction(
            agent: $agent,
            session: null,
            message: 'what is this?',
            app: $app,
            company: $company,
            user: $user,
            handler: $handler,
            media: [$imagePath],
        )->execute();

        unlink($imagePath);

        $this->assertSame('I see a 1x1 image.', $result);
    }

    public function testDispatchesCaptionJobToRememberImagesInHistory(): void
    {
        Queue::fake();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        $imagePath = $this->writeTempPng();
        $response = new AgentResponse('inv-4', 'noted', new Usage(1, 1), new Meta());

        $handler = Mockery::mock(KanvasLaravelAgent::class);
        $handler->shouldReceive('promptWithConfig')->once()->andReturn($response);

        // Session with an entity → the action writes an AgentHistory row and captions its images.
        $session = Mockery::mock(Session::class)->makePartial();
        $session->shouldReceive('entity')->andReturn($agent);
        $session->uuid = 'sess-img-1';

        new RunLaravelAgentChatAction(
            agent: $agent,
            session: $session,
            message: 'check this',
            app: $app,
            company: $company,
            user: $user,
            handler: $handler,
            media: [$imagePath],
        )->execute();

        unlink($imagePath);

        Queue::assertPushed(
            DescribeMessageAttachmentsJob::class,
            static fn (DescribeMessageAttachmentsJob $job): bool => $job->target === CaptionTargetEnum::AGENT_HISTORY
                && $job->attachmentUrls === [$imagePath],
        );
    }

    public function testForwardsPdfToTheModelAsADocumentAttachment(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        $pdfPath = $this->writeTempPdf();
        $response = new AgentResponse('inv-5', 'I read the PDF.', new Usage(1, 1), new Meta());

        $handler = Mockery::mock(KanvasLaravelAgent::class);
        $handler->shouldReceive('promptWithConfig')
            ->once()
            ->with(
                'summarize this',
                Mockery::on(
                    static fn (array $attachments): bool => count($attachments) === 1 && $attachments[0] instanceof Base64Document,
                ),
            )
            ->andReturn($response);

        new RunLaravelAgentChatAction(
            agent: $agent,
            session: null,
            message: 'summarize this',
            app: $app,
            company: $company,
            user: $user,
            handler: $handler,
            media: [$pdfPath],
        )->execute();

        unlink($pdfPath);

        $this->assertTrue(true);
    }

    private function writeTempPng(): string
    {
        // 1x1 transparent PNG — finfo detects image/png, no network needed.
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
        $path = tempnam(sys_get_temp_dir(), 'img') . '.png';
        file_put_contents($path, $bytes);

        return $path;
    }

    private function writeTempPdf(): string
    {
        // Minimal PDF — the %PDF- header is enough for finfo to report application/pdf.
        $bytes = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";
        $path = tempnam(sys_get_temp_dir(), 'doc') . '.pdf';
        file_put_contents($path, $bytes);

        return $path;
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
