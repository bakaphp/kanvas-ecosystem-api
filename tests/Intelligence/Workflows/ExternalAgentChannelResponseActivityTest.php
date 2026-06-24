<?php

declare(strict_types=1);

namespace Tests\Intelligence\Workflows;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Sessions\DataTransferObject\AiChatMessagePayload;
use Kanvas\Intelligence\Workflows\ExternalAgentChannelResponseActivity;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

/**
 * Locks the gating behavior of ExternalAgentChannelResponseActivity: only messages flagged
 * from_ia / from_orchestrator are delivered, and a from-phone is required only for the
 * phone-based channels (SMS / WhatsApp), never for email.
 */
class ExternalAgentChannelResponseActivityTest extends TestCase
{
    use DatabaseTransactions;
    use HasIntegrationCompany;

    private const string FROM_PHONE = '+19998887777';
    private const string CUSTOMER_PHONE = '+15551234567';

    public function testRejectsMessageWithOnlyOneExternalAiFlag(): void
    {
        // Both from_ia AND from_orchestrator are required for now — a single flag is not enough.
        [$app, $channel, $message] = $this->makeChannelMessage(
            verb: 'sms',
            content: 'Hello there',
            fromIa: true,
            fromOrchestrator: false,
        );

        $result = new ExternalAgentChannelResponseActivity(0, now()->toDateTimeString(), StoredWorkflow::make(), [])->execute(
            $channel,
            $app,
            ['message' => $message, 'from' => self::FROM_PHONE],
        );

        $this->assertStringContainsString('not from an external AI', $result['message'] ?? '');
    }

    public function testRequiresFromPhoneForSms(): void
    {
        [$app, $channel, $message] = $this->makeChannelMessage(
            verb: 'sms',
            content: 'Orchestrator says hi',
            fromIa: true,
            fromOrchestrator: true,
        );

        $result = new ExternalAgentChannelResponseActivity(0, now()->toDateTimeString(), StoredWorkflow::make(), [])->execute(
            $channel,
            $app,
            ['message' => $message],
        );

        $this->assertStringContainsString('From phone number is required for sms', $result['message'] ?? '');
    }

    public function testEmailDoesNotRequireFromPhone(): void
    {
        [$app, $channel, $message] = $this->makeChannelMessage(
            verb: 'email',
            content: 'Orchestrator email body',
            fromIa: true,
            fromOrchestrator: true,
        );

        $result = new ExternalAgentChannelResponseActivity(0, now()->toDateTimeString(), StoredWorkflow::make(), [])->execute(
            $channel,
            $app,
            ['message' => $message],
        );

        // Email bypasses the phone gate — whatever the downstream send does, it must never be
        // rejected for a missing from-phone.
        $combined = ($result['message'] ?? '') . ($result['error'] ?? '');
        $this->assertStringNotContainsString('From phone number is required', $combined);
    }

    public function testRejectsEmptyContentAndNoFiles(): void
    {
        [$app, $channel, $message] = $this->makeChannelMessage(
            verb: 'sms',
            content: '',
            fromIa: true,
            fromOrchestrator: true,
        );

        $result = new ExternalAgentChannelResponseActivity(0, now()->toDateTimeString(), StoredWorkflow::make(), [])->execute(
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
