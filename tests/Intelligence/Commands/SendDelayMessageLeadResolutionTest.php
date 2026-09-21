<?php

declare(strict_types=1);

namespace Tests\Intelligence\Commands;

use App\Console\Commands\Intelligence\Messaging\SendDelayMessageCommand;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AgentReachOutOnChannelAction links the outbound draft to the People first and the Lead
 * second, and Message::entity() only returns the first link. The delay sweep must still find
 * the Lead, whichever of the two links it lands on.
 */
class SendDelayMessageLeadResolutionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'social', 'crm', 'ecosystem'];

    public function testResolvesTheLeadWhenThePeopleLinkComesFirst(): void
    {
        $lead = $this->createLead();
        $message = $this->createMessage();
        $message->addEntity($lead->people);
        $message->addEntity($lead);

        $this->assertInstanceOf(People::class, $message->entity());
        $this->assertSame($lead->getId(), $this->resolveLead($message)?->getId());
    }

    public function testResolvesTheLeadWhenOnlyTheLeadIsLinked(): void
    {
        $lead = $this->createLead();
        $message = $this->createMessage();
        $message->addEntity($lead);

        $this->assertSame($lead->getId(), $this->resolveLead($message)?->getId());
    }

    public function testResolvesThePersonsLatestLeadWhenOnlyThePeopleIsLinked(): void
    {
        $olderLead = $this->createLead();
        $latestLead = Lead::factory()
            ->withAppId($olderLead->apps_id)
            ->withCompanyId($olderLead->companies_id)
            ->create(['people_id' => $olderLead->people_id]);

        $message = $this->createMessage();
        $message->addEntity($olderLead->people);

        $this->assertSame($latestLead->getId(), $this->resolveLead($message)?->getId());
    }

    public function testReturnsNullWhenTheMessageHasNoLeadOrPeople(): void
    {
        $this->assertNull($this->resolveLead($this->createMessage()));
    }

    private function resolveLead(Message $message): ?Lead
    {
        $resolve = new ReflectionMethod(SendDelayMessageCommand::class, 'resolveLead');

        return $resolve->invoke(new SendDelayMessageCommand(), $message->fresh());
    }

    private function createLead(): Lead
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads']
        );

        return Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();
    }

    private function createMessage(): Message
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $messageType = MessageType::firstOrCreate(
            ['apps_id' => $app->getId(), 'languages_id' => 1, 'verb' => 'twilio-sms'],
            ['name' => 'Twilio SMS']
        );

        $message = new Message();
        $message->apps_id = $app->getId();
        $message->companies_id = $company->getId();
        $message->users_id = $user->getId();
        $message->message_types_id = $messageType->getId();
        $message->message = ['content' => 'Delayed reach-out draft'];
        $message->is_locked = 1;
        $message->is_public = 0;
        $message->save();

        return $message;
    }
}
