<?php

declare(strict_types=1);

namespace Tests\Intelligence\Workflows;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Leads\Actions\Communication\DeliverConversationMessageAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\SmsOptOutNoticeService;
use Kanvas\Intelligence\Workflows\ExternalAgentChannelResponseActivity;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Repositories\MessagesRepository;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;
use Tests\Workflow\Support\WatchesWorkflowEvents;

/**
 * Only messages flagged from_ia / from_orchestrator are delivered, and they leave the way a
 * person's reply does: on the company's connection for the medium, with no number to name.
 */
class ExternalAgentChannelResponseActivityTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;
    use HasIntegrationCompany;
    use WatchesWorkflowEvents;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social', 'intelligence'];

    private const string FROM_PHONE = '+19998887777';
    private const string CUSTOMER_PHONE = '+18095550802';

    public function testRejectsMessageWithOnlyOneExternalAiFlag(): void
    {
        // Both from_ia AND from_orchestrator are required for now — a single flag is not enough.
        [$app, $channel, $message] = $this->makeChannelMessage(
            verb: 'sms',
            content: 'Hello there',
            fromIa: true,
            fromOrchestrator: false,
        );

        $result = new ExternalAgentChannelResponseActivity(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        )->execute(
            $channel,
            $app,
            ['message' => $message, 'from' => self::FROM_PHONE],
        );

        $this->assertStringContainsString('not from an external AI', $result['message'] ?? '');
    }

    public function testATextLeavesOnTheCompanysConnectionWithNoNumberNamed(): void
    {
        $transport = $this->useFakeProvider();
        $this->defaultSmsConnection(number: '+18095550801');
        $aiTrigger = $this->watchWorkflowEvent(WorkflowEnum::TRIGGER_AI->value, Lead::class);
        [$app, $channel, $message] = $this->makeChannelMessage(
            verb: 'sms',
            content: 'Orchestrator says hi',
            fromIa: true,
            fromOrchestrator: true,
        );

        $result = new ExternalAgentChannelResponseActivity(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        )->execute(
            $channel,
            $app,
            ['message' => $message],
        );

        $this->assertStringContainsString('Message sent to lead via sms', $result['message'] ?? '');
        $this->assertCount(1, $transport->sent);
        $this->assertStringContainsString(
            SmsOptOutNoticeService::NOTICE,
            $transport->sent[0]['text'],
            'the first text of a conversation carries the opt-out clause whoever wrote it'
        );
        $this->assertTrue((bool) $message->fresh()->is_un_response);
        $this->assertCount(0, $this->firedWith($aiTrigger), 'another AI writing does not take the lead over');
    }

    public function testATextTheCompanyCannotSendIsNotedAndAnswersNothing(): void
    {
        $transport = $this->useFakeProvider();
        [$app, $channel, $message] = $this->makeChannelMessage(
            verb: 'sms',
            content: 'Orchestrator says hi',
            fromIa: true,
            fromOrchestrator: true,
        );

        $result = new ExternalAgentChannelResponseActivity(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        )->execute(
            $channel,
            $app,
            ['message' => $message],
        );

        $this->assertStringContainsString('no sms connection', $result['message'] ?? '');
        $this->assertSame([], $transport->sent);
        $this->assertFalse((bool) $message->fresh()->is_un_response);
        $this->assertTrue(
            MessagesRepository::getLatestMessagesByLead($channel->entityData(), 20)->contains(
                fn (Message $note): bool => $note->tags()
                    ->where('name', DeliverConversationMessageAction::NOT_DELIVERED_TAG)
                    ->exists()
            )
        );
    }

    public function testRejectsEmptyContentAndNoFiles(): void
    {
        [$app, $channel, $message] = $this->makeChannelMessage(
            verb: 'sms',
            content: '',
            fromIa: true,
            fromOrchestrator: true,
        );

        $result = new ExternalAgentChannelResponseActivity(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        )->execute(
            $channel,
            $app,
            ['message' => $message, 'from' => self::FROM_PHONE],
        );

        $this->assertStringContainsString('content and files are both empty', $result['message'] ?? '');
    }

    /**
     * @return array{0: Apps, 1: Channel, 2: Message}
     */
    private function makeChannelMessage(
        string $verb,
        string $content,
        bool $fromIa,
        bool $fromOrchestrator,
    ): array {
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
            'value' => self::CUSTOMER_PHONE,
            'weight' => 1,
        ]);

        $channel = new CreateChannelAction(
            ChannelDto::from([
                'apps' => $app,
                'companies' => $company,
                'users' => $lead->user,
                'entity_id' => $lead->getId(),
                'entity_namespace' => Lead::class,
                'name' => 'External AI — ' . $lead->getId(),
                'slug' => 'external-ai-' . $lead->getId(),
            ])
        )->execute();

        $messageType = MessageType::firstOrCreate(
            ['apps_id' => $app->getId(), 'languages_id' => 1, 'verb' => $verb],
            ['name' => ucfirst($verb)]
        );

        $input = new MessageInput(
            app: $app,
            company: $company,
            user: $user,
            type: $messageType,
            message: AiChatMessagePayload::from([
                'content' => $content,
                'from_me' => true,
                'from_ia' => $fromIa,
            ])->toArray() + ['from_orchestrator' => $fromOrchestrator],
            is_public: 1,
        );
        $create = new CreateMessageAction($input);
        $create->runWorkflow = false;
        $message = $create->execute();

        $message->addEntity($lead);
        $channel->addMessage($message);

        return [$app, $channel, $message];
    }
}
