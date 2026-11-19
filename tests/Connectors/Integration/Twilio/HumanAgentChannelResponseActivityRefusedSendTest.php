<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Twilio;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Enums\CustomFieldEnum;
use Kanvas\Connectors\Twilio\Workflows\HumanAgentChannelResponseActivity;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Events\MessageDeliveryStatusEvent;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Override;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\Connectors\Traits\RefusesLeadSms;
use Tests\TestCase;

/**
 * A text the pre-send validation refuses never reaches Twilio, so no status callback will ever
 * report it: the person who wrote it has to be told at send time.
 */
class HumanAgentChannelResponseActivityRefusedSendTest extends TestCase
{
    use DatabaseTransactions;
    use HasIntegrationCompany;
    use RefusesLeadSms;

    private const string REASON = 'VOIP without SMS';

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social'];

    public function testARefusedTextIsMarkedFailedAndTheActivityFails(): void
    {
        Event::fake([MessageDeliveryStatusEvent::class]);

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->setIntegration($app, IntegrationsEnum::INTERNAL, 'internal', $company, $user);

        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();
        $lead->people->contacts()->create([
            'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
            'value' => '+15551234567',
            'weight' => 1,
        ]);

        $channel = new CreateChannelAction(
            ChannelDto::from([
                'apps' => $app,
                'companies' => $company,
                'users' => $lead->user,
                'entity_id' => $lead->getId(),
                'entity_namespace' => Lead::class,
                'name' => 'Refused text — ' . $lead->getId(),
                'slug' => 'refused-text-' . $lead->getId(),
            ])
        )->execute();

        $create = new CreateMessageAction(new MessageInput(
            app: $app,
            company: $company,
            user: $user,
            type: MessageType::firstOrCreate(
                ['apps_id' => $app->getId(), 'languages_id' => 1, 'verb' => 'sms'],
                ['name' => 'Sms']
            ),
            message: ['content' => 'Hi from a human', 'from_me' => true, 'from_human' => true],
            is_public: 1,
        ));
        $create->runWorkflow = false;
        $message = $create->execute();

        $message->addEntity($lead);
        $channel->addMessage($message);

        $sender = $this->smsRefusingSender($lead, self::REASON);
        $activity = new class (
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        ) extends HumanAgentChannelResponseActivity {
            public SendMessageToLeadAction $fakeSender;

            #[Override]
            protected function sender(Lead $lead): SendMessageToLeadAction
            {
                return $this->fakeSender;
            }
        };
        $activity->fakeSender = $sender;

        $result = $activity->execute($channel, $app, ['message' => $message, 'from' => '+15555550100']);

        $this->assertSame(StatusEnum::FAILED, $activity->workflowStatus());
        $this->assertSame('Message not sent to lead via sms: ' . self::REASON, $result['message']);
        $this->assertFalse($result['result']['success']);

        $message->refresh();
        $delivery = $message->message['delivery'] ?? null;
        $this->assertIsArray($delivery);
        $this->assertSame('failed', $delivery['status']);
        $this->assertSame(self::REASON, $delivery['error_message']);
        $this->assertNull($delivery['error_code']);
        $this->assertNull($delivery['message_sid']);
        $this->assertSame('failed', $message->get(CustomFieldEnum::CURRENT_STATUS->value));
        Event::assertDispatched(MessageDeliveryStatusEvent::class);

        $this->assertFalse((bool) $message->is_un_response);
        $this->assertNull($message->response_message_id);
    }
}
