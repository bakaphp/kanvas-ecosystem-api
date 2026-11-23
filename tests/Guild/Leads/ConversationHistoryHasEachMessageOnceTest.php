<?php

declare(strict_types=1);

namespace Tests\Guild\Leads;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Services\LeadChannelService;
use Kanvas\Guild\Leads\Summary\LeadConversationMessages;
use Kanvas\Intelligence\Agents\Actions\Outreach\PersistOutboundMessageAction;
use Kanvas\Intelligence\Agents\Neuron\Stores\EntityRollupMessageStore;
use Kanvas\Social\Messages\Models\AppModuleMessage;
use Kanvas\Social\Messages\Repositories\MessagesRepository;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\TestCase;

/**
 * A message of a lead's conversation sits on three channels and is recorded from several paths
 * (the inbound, a tool send, a rep's delivered reply). The agent's history and the lead summary read
 * the message's record links, not its channels, so each message must reach them exactly once however
 * many paths recorded it.
 */
final class ConversationHistoryHasEachMessageOnceTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social', 'workflow', 'intelligence'];

    public function testAMessageRecordedFromSeveralPathsReachesTheHistoriesOnce(): void
    {
        $person = $this->personWithCellphone('+18095550901');
        $lead = $this->leadOf($person);
        $conversation = $this->conversationChannel($lead, 'twilio-');
        $user = auth()->user();

        $inbound = $this->fileInConversation(
            $conversation,
            $lead,
            'twilio-sms',
            ['content' => 'Is the car still available?', 'from_me' => false]
        );
        $inbound->addEntity($person);
        new LeadChannelService()->recordOnLeadAndPerson($inbound, $lead, $user);
        new LeadChannelService()->recordOnLeadAndPerson($inbound, $lead, $user);

        ['message' => $sent] = new PersistOutboundMessageAction(
            entity: $lead,
            user: $user,
            channelType: LeadCommunicationChannelEnum::SMS->value,
            recipient: '+18095550901',
            content: 'Yes, come by today',
        )->execute(['messages' => [['sid' => 'SM' . uniqid()]]]);
        new LeadChannelService()->recordOnLeadAndPerson($sent, $lead, $user);

        foreach ([$inbound, $sent] as $message) {
            foreach ([$lead, $person] as $record) {
                $this->assertSame(
                    1,
                    AppModuleMessage::query()
                        ->forEntity($record)
                        ->where('message_id', $message->getId())
                        ->count(),
                    ($message->is($inbound) ? 'inbound' : 'sent') . ' on ' . class_basename($record) . ': one link per record'
                );
            }
        }

        foreach ([$person, $lead] as $record) {
            $ids = array_map(
                fn ($turn): string => (string) $turn->getId(),
                new EntityRollupMessageStore($lead->app, $lead->company, $user, $record)->loadActive('thread')
            );

            $this->assertSame(
                ['social:' . $inbound->getId(), 'social:' . $sent->getId()],
                $ids,
                'the agent reads each message once, in order'
            );
        }

        $this->assertSame(
            [$inbound->getId(), $sent->getId()],
            LeadConversationMessages::idsAfter($lead, 0),
            'the lead summary counts each message once'
        );
        $this->assertSame(
            2,
            MessagesRepository::getLatestMessagesByLead($lead, 20)->pluck('id')->unique()->count()
        );
    }
}
