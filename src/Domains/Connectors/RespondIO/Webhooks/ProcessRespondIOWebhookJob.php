<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Webhooks;

use Baka\Support\Str;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\RespondIO\Enums\MessageTypeEnum;
use Kanvas\Connectors\RespondIO\Enums\WebhookEventEnum;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDTO;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\Actions\CreateLeadReceiverAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadData;
use Kanvas\Guild\Leads\DataTransferObject\LeadReceiver;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver as LeadReceiverModel;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Guild\LeadSources\Actions\CreateLeadSourceAction;
use Kanvas\Guild\LeadSources\DataTransferObject\LeadSource;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;
use Spatie\LaravelData\DataCollection;

class ProcessRespondIOWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;
        $eventType = $payload['event'] ?? 'unknown';

        $result = match ($eventType) {
            WebhookEventEnum::NEW_INCOMING_MESSAGE->value => $this->handleIncomingMessage($payload),
            WebhookEventEnum::NEW_OUTGOING_MESSAGE->value => $this->handleOutgoingMessage($payload),
            WebhookEventEnum::CONVERSATION_OPENED->value,
            WebhookEventEnum::CONVERSATION_CLOSED->value,
            WebhookEventEnum::CONTACT_UPDATED->value,
            WebhookEventEnum::CONTACT_CREATED->value => $this->handleGenericEvent($payload),
            default => $this->handleGenericEvent($payload),
        };

        return [
            'message' => 'RespondIO webhook processed successfully',
            'event_type' => $eventType,
            'result' => $result,
        ];
    }

    protected function handleIncomingMessage(array $payload): array
    {
        $data = $payload['data'] ?? [];
        $contact = $data['contact'] ?? [];
        $messageData = $data['message'] ?? [];
        $channelData = $data['channel'] ?? [];

        $phone = $contact['phone'] ?? null;
        $contactId = (string) ($contact['id'] ?? '');
        $firstName = $contact['firstName'] ?? null;
        $lastName = $contact['lastName'] ?? null;
        $messageId = (string) ($messageData['id'] ?? Str::uuid()->toString());
        $messageType = MessageTypeEnum::getMessageType($messageData);
        $text = $this->extractMessageText($messageData, $messageType);

        if ($phone === null && $contactId === '') {
            return ['error' => 'Missing contact phone or ID in payload'];
        }

        $identifier = $phone ?? $contactId;
        $displayName = trim(($firstName ?? '') . ' ' . ($lastName ?? ''));
        if ($displayName === '') {
            $displayName = $identifier;
        }

        $people = $this->processContactFromMessage(
            $identifier,
            $firstName,
            $lastName
        );

        if (! $people) {
            return ['error' => 'Failed to process contact'];
        }

        $lead = $this->createLeadFromPeople($people);
        $lead->set(LeadsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value, 'respondio');
        $lead->set(LeadsConfigurationEnum::IS_ENGAGEMENT->value, true);

        $messageSlug = $this->createMessageSlug($messageId, $identifier);
        $channel = $this->getOrCreateChannel($identifier, $displayName, $lead);

        $existingMessage = Message::where('uuid', $messageSlug)
            ->where('companies_id', $this->receiver->company->getId())
            ->where('apps_id', $this->receiver->app->getId())
            ->first();

        if ($existingMessage) {
            $message = $existingMessage;
        } else {
            $messageTypeModel = new CreateMessageTypeAction(
                new MessageTypeInput(
                    $this->receiver->app->getId(),
                    0,
                    $messageType->value,
                    $messageType->value,
                )
            )->execute();

            $messageInput = new MessageInput(
                app: $this->receiver->app,
                company: $this->receiver->company,
                user: $this->receiver->user,
                type: $messageTypeModel,
                message: [
                    'content' => $text,
                    'raw_data' => $data,
                    'message_id' => $messageId,
                    'chat_jid' => $identifier,
                    'from_me' => false,
                    'respondio_channel' => $channelData['name'] ?? null,
                    'respondio_contact_id' => $contactId,
                ],
                is_public: 1,
                slug: $messageSlug,
                tags: [$identifier]
            );

            $message = new CreateMessageAction($messageInput)->execute();
        }

        $message->addEntity($lead);
        $message->addTag('engagement');

        $channel->addMessage($message);

        $channel->fireWorkflow(
            WorkflowEnum::AFTER_ADDING_MESSAGE_TO_CHANNEL->value,
            true,
            [
                'message' => $message,
                'user' => $message->user,
                'app' => $message->app,
                'company' => $message->company,
                'text' => $text,
                'communication_channel' => 'respondio',
            ]
        );

        return [
            'message_id' => $message->getId(),
            'uuid' => $message->uuid,
            'channel_id' => $channel->getId(),
            'chat_jid' => $identifier,
            'text' => $text,
            'is_from_me' => false,
            'type' => $messageType->value,
        ];
    }

    protected function handleOutgoingMessage(array $payload): array
    {
        $data = $payload['data'] ?? [];
        $contact = $data['contact'] ?? [];
        $messageData = $data['message'] ?? [];

        $phone = $contact['phone'] ?? null;
        $contactId = (string) ($contact['id'] ?? '');
        $messageId = (string) ($messageData['id'] ?? Str::uuid()->toString());
        $messageType = MessageTypeEnum::getMessageType($messageData);
        $text = $this->extractMessageText($messageData, $messageType);

        $identifier = $phone ?? $contactId;
        if ($identifier === '') {
            return ['error' => 'Missing contact identifier'];
        }

        $messageSlug = $this->createMessageSlug($messageId, $identifier);
        $channel = $this->getOrCreateChannel($identifier);

        $existingMessage = Message::where('uuid', $messageSlug)
            ->where('companies_id', $this->receiver->company->getId())
            ->where('apps_id', $this->receiver->app->getId())
            ->first();

        if (! $existingMessage) {
            $messageTypeModel = new CreateMessageTypeAction(
                new MessageTypeInput(
                    $this->receiver->app->getId(),
                    0,
                    $messageType->value,
                    $messageType->value,
                )
            )->execute();

            $messageInput = new MessageInput(
                app: $this->receiver->app,
                company: $this->receiver->company,
                user: $this->receiver->user,
                type: $messageTypeModel,
                message: [
                    'content' => $text,
                    'raw_data' => $data,
                    'message_id' => $messageId,
                    'chat_jid' => $identifier,
                    'from_me' => true,
                ],
                is_public: 1,
                slug: $messageSlug,
                tags: [$identifier]
            );

            $existingMessage = new CreateMessageAction($messageInput)->execute();
        }

        $channel->addMessage($existingMessage);

        return [
            'message_id' => $existingMessage->getId(),
            'uuid' => $existingMessage->uuid,
            'channel_id' => $channel->getId(),
            'is_from_me' => true,
        ];
    }

    protected function handleGenericEvent(array $payload): array
    {
        return [
            'event' => $payload['event'] ?? 'unknown',
            'status' => 'acknowledged',
        ];
    }

    protected function processContactFromMessage(
        string $identifier,
        ?string $firstName,
        ?string $lastName
    ): ?People {
        $phoneNumber = Str::normalizePhoneNumber($identifier);
        $phoneNumberWithCountryCode = str_replace('+', '', $identifier);

        $existingCustomer = PeoplesRepository::getByPhoneNumber(
            app: $this->receiver->app,
            company: $this->receiver->company,
            phoneNumbers: [$phoneNumber, $phoneNumberWithCountryCode]
        )->first();

        if ($existingCustomer) {
            return $existingCustomer;
        }

        $contactData = [
            [
                'value' => $phoneNumber,
                'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
                'weight' => 100,
            ],
        ];

        $peopleDto = new PeopleDTO(
            app: $this->receiver->app,
            branch: $this->receiver->company->defaultBranch,
            user: $this->receiver->user,
            firstname: $firstName ?? $phoneNumber,
            contacts: Contact::collect($contactData, DataCollection::class),
            address: Address::collect([], DataCollection::class),
            lastname: $lastName ?? '',
            custom_fields: [
                'respondio_phone' => $identifier,
            ],
            tags: ['respondio', 'ai-agent']
        );

        return new CreatePeopleAction($peopleDto)->execute();
    }

    protected function createLeadFromPeople(People $people): Lead
    {
        $activeLead = LeadsRepository::getPeopleActiveLead($people);

        if ($activeLead) {
            return $activeLead;
        }

        $leadTypeName = $this->receiver->configuration['lead_type'] ?? 'Warm';

        $leadType = LeadType::fromApp($people->app)
            ->fromCompany($people->company)
            ->where('name', $leadTypeName)
            ->first();

        $leadSource = new CreateLeadSourceAction(
            new LeadSource(
                $people->app,
                $people->company,
                $leadType->getId(),
                'RespondIO',
                true,
                'RespondIO'
            )
        )->execute();

        $receiverId = $this->receiver->configuration['receiver_id'] ?? null;

        if ($receiverId !== null) {
            $leadReceiver = LeadReceiverModel::fromApp($people->app)
                ->fromCompany($people->company)
                ->where('id', $receiverId)
                ->where('is_deleted', 0)
                ->firstOrFail();
        } else {
            $leadReceiver = new CreateLeadReceiverAction(
                new LeadReceiver(
                    app: $people->app,
                    branch: $people->company->defaultBranch,
                    user: $people->user,
                    agent: $people->user,
                    name: 'Agent',
                    source: 'AI Agent',
                    isDefault: false,
                    lead_sources_id: $leadSource->getId(),
                    lead_types_id: $leadType->getId()
                )
            )->execute();
        }

        $pipelineId = $this->receiver->configuration['pipeline_id'] ?? null;
        $pipeline = null;

        if ($pipelineId !== null) {
            $pipeline = Pipeline::fromApp($people->app)
                ->fromCompany($people->company)
                ->where('id', $pipelineId)
                ->where('is_deleted', 0)
                ->first();
        }

        $leadData = new LeadData(
            app: $people->app,
            branch: $people->company->defaultBranch,
            user: $people->user,
            title: $people->name . ' RespondIO Opp',
            pipeline_stage_id: $pipeline?->firstStage?->getId() ?? 0,
            people: new PeopleDTO(
                app: $people->app,
                branch: $people->company->defaultBranch,
                user: $people->user,
                firstname: $people->firstname,
                contacts: Contact::collect($people->contacts()->get()->toArray(), DataCollection::class),
                address: Address::collect([], DataCollection::class),
                lastname: $people->lastname,
                id: $people->id,
                runWorkflow: false
            ),
            leads_owner_id: $leadReceiver->rotation ? $leadReceiver->rotation->getAgent()->id : 0,
            status_id: 0,
            type_id: $leadType->getId(),
            source_id: $leadSource->getId(),
            receiver_id: $leadReceiver->getId()
        );

        $lead = new CreateLeadAction($leadData)->execute();
        $lead->addTags([
            'respondio',
            'ai-agent',
        ]);
        $lead->set('sub_source', 'RespondIO');

        return $lead;
    }

    protected function getOrCreateChannel(string $identifier, ?string $name = null, ?Lead $lead = null): Channel
    {
        $cleanedPhone = Str::normalizePhoneNumber($identifier);
        $slug = 'respondio-' . $cleanedPhone;

        return DB::transaction(function () use ($slug, $name, $identifier, $lead): Channel {
            $channel = Channel::where('slug', $slug)
                ->where('companies_id', $this->receiver->company->getId())
                ->where('apps_id', $this->receiver->app->getId())
                ->lockForUpdate()
                ->first();

            if (! $channel) {
                $channel = new Channel();
                $channel->name = $name ?? 'RespondIO Chat: ' . $identifier;
                $channel->description = 'RespondIO Chat: ' . $identifier;
                $channel->slug = $slug;
                $channel->companies_id = $this->receiver->company->getId();
                $channel->apps_id = $this->receiver->app->getId();

                if ($lead) {
                    $channel->entity_namespace = get_class($lead);
                    $channel->entity_id = $lead->getId();
                }

                $channel->save();

                $channel->addTags(
                    ['respondio', 'ai-agent'],
                    $this->receiver->app,
                    $this->receiver->user,
                    $this->receiver->company
                );

                $channel->addCategory(
                    'ai-agent',
                    $this->receiver->app,
                    $this->receiver->user,
                    $this->receiver->company
                );
            } elseif ($name !== null && $channel->name !== $name) {
                $channel->name = $name;
                $channel->save();
            }

            if ($lead && ($channel->entity_namespace === null || $channel->entity_namespace === '')) {
                $channel->entity_namespace = get_class($lead->people);
                $channel->entity_id = $lead->people->getId();
                $channel->update();
            }

            if ($channel->id) {
                $channel->set(
                    ConfigurationEnum::AGENT_CHANNEL_TYPE->value,
                    'RespondIO'
                );
            }

            return $channel;
        }, 5);
    }

    protected function createMessageSlug(string $messageId, string $identifier): string
    {
        return 'respondio-' . Str::slug($messageId . '-' . $identifier);
    }

    protected function extractMessageText(array $messageData, MessageTypeEnum $messageType): ?string
    {
        return match ($messageType) {
            MessageTypeEnum::TEXT => $messageData['text'] ?? null,
            MessageTypeEnum::IMAGE,
            MessageTypeEnum::VIDEO => $messageData['caption'] ?? $messageData['attachment']['description'] ?? null,
            MessageTypeEnum::FILE,
            MessageTypeEnum::AUDIO => $messageData['attachment']['fileName'] ?? $messageData['caption'] ?? null,
            MessageTypeEnum::LOCATION => $messageData['address'] ?? null,
            MessageTypeEnum::QUICK_REPLY => $messageData['title'] ?? null,
            default => $messageData['text'] ?? null,
        };
    }
}
