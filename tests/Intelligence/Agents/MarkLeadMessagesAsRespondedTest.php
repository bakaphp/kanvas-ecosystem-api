<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Actions\MarkLeadMessagesAsRespondedAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\SystemModules\Models\SystemModules;
use Tests\TestCase;

/**
 * An answer covers what the responder read. A text that arrived while the answer was being written
 * was not read, so it must stay unanswered and get a turn of its own.
 */
final class MarkLeadMessagesAsRespondedTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social'];

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );

        $this->lead = Lead::factory()
            ->withAppAndCompany(app(Apps::class)->getId(), auth()->user()->getCurrentCompany()->getId())
            ->create();
    }

    public function testOnlyWhatTheAnswerCoversIsMarkedWhenTheInboundIsKnown(): void
    {
        $earlier = $this->message('Hi');
        $answered = $this->message('Is it still available?');
        $burstPart = $this->message('The blue one', parent: $answered);
        $midTurn = $this->message('Actually, never mind the blue one');
        $reply = $this->message('Yes, it is');

        $marked = new MarkLeadMessagesAsRespondedAction($this->lead, $reply, $answered)->execute();

        $this->assertSame(4, $marked);
        foreach ([$earlier, $answered, $burstPart, $reply] as $covered) {
            $this->assertTrue((bool) $covered->fresh()->is_un_response);
            $this->assertSame($reply->getId(), (int) $covered->fresh()->response_message_id);
        }
        $this->assertFalse((bool) $midTurn->fresh()->is_un_response, 'a text nobody read is not answered');
    }

    public function testEveryUnansweredMessageIsMarkedWhenTheCallerNamesNoInbound(): void
    {
        $first = $this->message('Hi');
        $second = $this->message('Anyone there?');
        $reply = $this->message('Here I am');

        $marked = new MarkLeadMessagesAsRespondedAction($this->lead, $reply)->execute();

        $this->assertSame(3, $marked);
        $this->assertTrue((bool) $first->fresh()->is_un_response);
        $this->assertTrue((bool) $second->fresh()->is_un_response);
    }

    private function message(string $content, ?Message $parent = null): Message
    {
        $message = Message::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create(['message' => ['content' => $content]]);

        if ($parent !== null) {
            $message->parent_id = $parent->getId();
            $message->saveOrFail();
        }

        $message->addEntity($this->lead);

        return $message;
    }
}
