<?php

declare(strict_types=1);

namespace Tests\Intelligence\Commands;

use App\Console\Commands\Intelligence\Messaging\SendDelayMessageCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\Outreach\AgentReachOutAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
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

    public function testTheSweepSendsADelayedDraftCreatedByTheRealReachOut(): void
    {
        $lead = $this->createLead();
        $lead->people->contacts()->create([
            'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
            'value' => '+15551234567',
            'weight' => 1,
        ]);
        $lead->set('ai_mode', IntelligenceModeEnum::SUPPORT->value);
        $lead->company->set(
            IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value,
            auth()->user()->getId(),
        );

        new AgentReachOutAction($lead, ['agent_id' => $this->createStubAgent()->getId()])->execute();

        $draft = Message::fromApp($lead->app)
            ->fromCompany($lead->company)
            ->where('is_locked', 1)
            ->latest('id')
            ->firstOrFail();

        $this->assertTrue($draft->hasTag(['agent-reach-out-delayed']));
        $this->assertInstanceOf(People::class, $draft->entity());

        $command = new RecordingSendDelayMessageCommand();
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));
        $command->processForTest($lead->company, $draft, 60);

        $this->assertSame($lead->getId(), $command->sentLead?->getId());
        $this->assertStringContainsString('Hola Mundo', (string) $command->sentContent);
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

    private function createStubAgent(): Agent
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create([
                'name' => 'Sales (Neuron Test)',
                'provider' => 'neuron',
                'handler' => SalesNeuronAgentStub::class,
            ]);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'name' => 'Reach-out Test',
                'agent_type_id' => $agentType->getId(),
                'soul' => 'Test',
                'instructions' => 'Always respond Hola Mundo',
                'output_format' => 'plain text',
            ]);
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

final class RecordingSendDelayMessageCommand extends SendDelayMessageCommand
{
    public ?Lead $sentLead = null;

    public ?string $sentContent = null;

    public function processForTest(Companies $company, Message $message, int $delayMinutes): void
    {
        $this->processMessage($company, $message, $delayMinutes);
    }

    protected function sendCrmDelayNote(
        Lead $lead,
        Message $message,
        Companies $company,
        int $delayMinutes
    ): bool {
        return true;
    }

    protected function sendDelayedMessage(Lead $lead, Message $message, string $messageContent): void
    {
        $this->sentLead = $lead;
        $this->sentContent = $messageContent;
    }
}
