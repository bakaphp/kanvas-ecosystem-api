<?php

declare(strict_types=1);

namespace Tests\Connectors\SalesAssist\Actions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Actions\RecordAgentReachOutAction;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Tests\TestCase;

final class RecordAgentReachOutActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social', 'intelligence'];

    public function testTheFirstMessageShowsOnTheLeadsProtocolChannel(): void
    {
        $lead = $this->lead();
        $message = $this->message('sms');

        new RecordAgentReachOutAction($lead, ['agent_id' => $this->agent()->getId(), 'message_ids_sent' => [$message->getId()]])->execute();

        $channels = Channel::query()
            ->whereHas('messages', fn ($query) => $query->where('messages.id', $message->getId()))
            ->get();

        $this->assertCount(1, $channels);
        $this->assertSame($message->getId(), (int) $channels->first()->last_message_id);
    }

    public function testAScheduledMessageIsRecordedLikeASentOne(): void
    {
        $lead = $this->lead();
        $message = $this->message('sms');

        new RecordAgentReachOutAction($lead, ['agent_id' => $this->agent()->getId(), 'message_ids_scheduled' => [$message->getId()]])->execute();

        $this->assertTrue(
            Channel::query()->whereHas('messages', fn ($query) => $query->where('messages.id', $message->getId()))->exists()
        );
    }

    public function testAReachOutThatSentNothingRecordsNothing(): void
    {
        $lead = $this->lead();
        $channels = Channel::query()->fromCompany($lead->company)->count();

        new RecordAgentReachOutAction($lead, ['status' => 'skipped'])->execute();

        $this->assertSame($channels, Channel::query()->fromCompany($lead->company)->count());
    }

    public function testAMessageOnAProtocolTheLeadCannotBeReachedOnIsLeftAlone(): void
    {
        $lead = $this->lead();
        $message = $this->message('whatsapp');

        new RecordAgentReachOutAction($lead, ['agent_id' => $this->agent()->getId(), 'message_ids_sent' => [$message->getId()]])->execute();

        $this->assertFalse(
            Channel::query()->whereHas('messages', fn ($query) => $query->where('messages.id', $message->getId()))->exists()
        );
    }

    private function agent(): Agent
    {
        return Agent::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create();
    }

    private function lead(): Lead
    {
        $lead = Lead::factory()
            ->withAppAndCompany(app(Apps::class)->getId(), auth()->user()->getCurrentCompany()->getId())
            ->create();

        $lead->people->addContact(ContactTypeEnum::CELLPHONE->getName(), '+18095550' . random_int(100, 999));

        return $lead;
    }

    private function message(string $protocol): Message
    {
        $message = Message::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create(['message' => ['content' => 'Hi, this is Sally.']]);
        $message->set('communicationChannel', $protocol);

        return $message;
    }
}
