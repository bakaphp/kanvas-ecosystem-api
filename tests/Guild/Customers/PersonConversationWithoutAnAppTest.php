<?php

declare(strict_types=1);

namespace Tests\Guild\Customers;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Guild\Customers\Services\PeopleChannelService;
use Kanvas\Guild\Leads\Services\LeadChannelService;
use Kanvas\Social\Channels\Models\Channel;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\TestCase;

/**
 * People from the app 1/2 era carry apps_id 0 or NULL. A message to or from one of them is still
 * recorded on their whole conversation, in the tenant the message was filed in.
 */
final class PersonConversationWithoutAnAppTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social', 'workflow', 'intelligence'];

    public function testAMessageIsRecordedOnThePersonInTheMessagesOwnTenant(): void
    {
        $person = $this->personWithCellphone('+18095550911');
        $lead = $this->leadOf($person);
        $message = $this->fileInConversation(
            $this->conversationChannel($lead, 'twilio-'),
            $lead,
            'twilio-sms',
            ['content' => 'Is the car still available?', 'from_me' => false]
        );

        DB::connection('crm')->table('peoples')->where('id', $person->getId())->update(['apps_id' => null]);
        $lead->refresh();
        $this->assertNull($lead->people->app);

        new LeadChannelService()->recordOnLeadAndPerson($message, $lead, auth()->user());

        $history = Channel::query()->where('slug', new PeopleChannelService()->slugFor($person))->sole();
        $this->assertSame($this->currentApp()->getId(), (int) $history->apps_id);
        $this->assertSame($this->currentCompany()->getId(), (int) $history->companies_id);
        $this->assertTrue($history->messages()->where('messages.id', $message->getId())->exists());
    }
}
