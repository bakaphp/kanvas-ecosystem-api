<?php

declare(strict_types=1);

namespace Tests\Intelligence\PipelineStages;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\PipelinesStages\Actions\CreateMessageFollowUpAction;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session;
use Kanvas\Intelligence\Sessions\Models\Session as SessionModel;
use Kanvas\Intelligence\Workflows\LeadAgentFirstMessageOutreachActivity;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Messages\Models\Message;
use ReflectionClass;
use Tests\TestCase;

/**
 * The first message and the pipeline-stage follow-up file their message through the lead's system
 * module, which links the lead alone. The person's history and the agent's memory of them are
 * read off the person, so a message linked to the lead alone is in neither.
 */
final class LegacyLeadMessagesAreFiledOnThePersonTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social', 'intelligence'];

    public function testTheFirstMessageIsFiledOnTheLeadAndItsPerson(): void
    {
        $lead = $this->lead();
        $activity = new ReflectionClass(LeadAgentFirstMessageOutreachActivity::class);

        /** @var Message $message */
        $message = $activity->getMethod('createMessage')->invoke(
            $activity->newInstanceWithoutConstructor(),
            $lead,
            'Hi, thanks for your interest',
            '+18095550710',
            'twilio-sms',
            false
        );

        $this->assertFiledOnLeadAndPerson($lead, $message);
    }

    public function testAPipelineStageFollowUpIsFiledOnTheLeadAndItsPerson(): void
    {
        $lead = $this->lead();
        $action = new ReflectionClass(CreateMessageFollowUpAction::class);
        $followUp = $action->newInstanceWithoutConstructor();

        $action->getProperty('lead')->setValue($followUp, $lead);
        $action->getProperty('session')->setValue($followUp, $this->sessionOn($lead));
        $action->getProperty('communicationChannel')->setValue($followUp, 'sms');
        $action->getProperty('log')->setValue($followUp, null);

        $this->assertFiledOnLeadAndPerson($lead, $followUp->persistMessage('Still interested?'));
    }

    private function assertFiledOnLeadAndPerson(Lead $lead, Message $message): void
    {
        $this->assertTrue($lead->is($message->entity(Lead::class)), 'filed on the lead');
        $this->assertTrue($lead->people->is($message->entity(People::class)), 'and on its person');
        $this->assertSame(2, $message->appModuleMessages()->count(), 'one link per record');
    }

    private function lead(): Lead
    {
        return Lead::factory()
            ->withAppAndCompany(app(Apps::class)->getId(), auth()->user()->getCurrentCompany()->getId())
            ->create();
    }

    private function sessionOn(Lead $lead): SessionModel
    {
        $channel = new CreateChannelAction(ChannelDto::from([
            'apps' => $lead->app,
            'companies' => $lead->company,
            'users' => $lead->user,
            'entity_id' => $lead->getId(),
            'entity_namespace' => Lead::class,
            'name' => 'Lead ' . $lead->getId() . ' Session',
            'slug' => 'lead_' . $lead->getId() . '_session',
        ]))->execute();

        $agent = Agent::factory()->create([
            'apps_id' => $lead->apps_id,
            'companies_id' => $lead->companies_id,
            'user_id' => auth()->user()->getId(),
        ]);

        return new CreateSessionAction(Session::from([
            'agent' => $agent,
            'channel' => $channel,
            'app' => $lead->app,
            'company' => $lead->company,
            'entity_id' => $lead->getId(),
            'entity_namespace' => Lead::class,
            'user' => $lead->user->toArray(),
            'canal_id' => 'lead_' . $lead->getId() . '_session',
            'userModel' => auth()->user(),
        ]))->execute();
    }
}
