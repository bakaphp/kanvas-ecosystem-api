<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Neuron\Tools\Common\ReadMessageContentTool;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;
use NeuronAI\Exceptions\ToolRunsExceededException;
use Tests\TestCase;

class ReadMessageContentToolTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'social'];

    public function testPagesThroughLongContent(): void
    {
        $content = str_repeat('a', 90000);
        $message = $this->createMessage($content);
        $tool = $this->tool();

        $first = $tool(message_id: $message->getId());

        $this->assertSame(90000, $first['total_length']);
        $this->assertSame(40000, mb_strlen($first['content']));
        $this->assertTrue($first['has_more']);
        $this->assertSame(40000, $first['next_offset']);

        $second = $tool(message_id: $message->getId(), offset: $first['next_offset']);

        $this->assertSame(40000, mb_strlen($second['content']));
        $this->assertSame(80000, $second['next_offset']);

        $third = $tool(message_id: $message->getId(), offset: $second['next_offset']);

        $this->assertSame(10000, mb_strlen($third['content']));
        $this->assertFalse($third['has_more']);
        $this->assertNull($third['next_offset']);
    }

    /**
     * The loop behind KANVAS-ECOSYSTEM-621: the model asks for the same page over and over. The
     * second identical read gets an instruction to move on, not another 40k chars.
     */
    public function testRepeatedReadOfTheSamePageIsRefused(): void
    {
        $message = $this->createMessage(str_repeat('b', 90000));
        $tool = $this->tool();

        $tool(message_id: $message->getId());

        // NeuronAI clones the registered tool per call — the ledger has to survive that.
        $repeat = clone $tool;
        $result = $repeat(message_id: $message->getId());

        $this->assertSame('', $result['content']);
        $this->assertFalse($result['has_more']);
        $this->assertNull($result['next_offset']);
        $this->assertStringContainsString('already received', $result['error']);
    }

    public function testOneTurnStopsAtTheCharBudget(): void
    {
        $message = $this->createMessage(str_repeat('c', 300000));
        $tool = $this->tool();

        $tool(message_id: $message->getId(), offset: 0);
        $tool(message_id: $message->getId(), offset: 40000);
        $lastPage = $tool(message_id: $message->getId(), offset: 80000);

        // 3 pages = 120000 chars = the whole turn budget, even though 180000 chars are left.
        $this->assertFalse($lastPage['has_more']);

        $beyond = $tool(message_id: $message->getId(), offset: 120000);

        $this->assertSame('', $beyond['content']);
        $this->assertFalse($beyond['has_more']);
        $this->assertStringContainsString('all one turn returns', $beyond['error']);
    }

    public function testEmptyMessageIsTerminalInsteadOfSilentlyEmpty(): void
    {
        $message = $this->createMessage('');
        $result = $this->tool()(message_id: $message->getId());

        $this->assertSame(0, $result['total_length']);
        $this->assertFalse($result['has_more']);
        $this->assertStringContainsString('no readable text', $result['error']);
    }

    public function testReadingPastTheEndIsTerminal(): void
    {
        $message = $this->createMessage('short content');
        $result = $this->tool()(message_id: $message->getId(), offset: 5000);

        $this->assertFalse($result['has_more']);
        $this->assertStringContainsString('already reached the end', $result['error']);
    }

    public function testACallCapEndsARunawayLoop(): void
    {
        $message = $this->createMessage(str_repeat('d', 90000));
        $tool = $this->tool();

        $this->expectException(ToolRunsExceededException::class);

        for ($call = 0; $call < 13; $call++) {
            $tool(message_id: $message->getId(), offset: $call * 40000);
        }
    }

    public function testUnknownMessageReturnsAnError(): void
    {
        $result = $this->tool()(message_id: 0);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('was not found', $result['error']);
    }

    public function testRunKeyIsScopedToThePage(): void
    {
        $tool = $this->tool();

        $tool->setInputs(['message_id' => 7, 'offset' => 40000]);
        $paged = $tool->getRunKey();

        $tool->setInputs(['message_id' => 7]);
        $first = $tool->getRunKey();

        $this->assertNotSame($paged, $first);
        $this->assertSame('read_message_content:7:0', $first);
    }

    private function tool(): ReadMessageContentTool
    {
        /** @var Users $user */
        $user = auth()->user();
        /** @var Companies $company */
        $company = $user->getCurrentCompany();

        return new ReadMessageContentTool()->withContext(app(Apps::class), $company, $user);
    }

    private function createMessage(string $content): Message
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $messageType = MessageType::where('apps_id', $app->getId())->first()
            ?? MessageType::factory()->create(['apps_id' => $app->getId()]);

        return Message::create([
            'apps_id' => $app->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
            'message_types_id' => $messageType->getId(),
            'message' => ['message' => $content],
            'is_public' => 1,
        ]);
    }
}
