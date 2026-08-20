<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Kanvas\Connectors\WaSender\DataTransferObject\InboundMessage;
use Kanvas\Connectors\WaSender\Enums\MessageTypeEnum;
use Kanvas\Connectors\WaSender\Services\ConversationChannelService;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDTO;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\Actions\CreateLeadReceiverAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as DataTransferObjectLead;
use Kanvas\Guild\Leads\DataTransferObject\LeadReceiver;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsEnumsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver as LeadReceiverModel;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Guild\Leads\Services\NotifyLeadStakeholdersService;
use Kanvas\Guild\LeadSources\Actions\CreateLeadSourceAction;
use Kanvas\Guild\LeadSources\DataTransferObject\LeadSource;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Channels\Repositories\ChannelRepository;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Spatie\LaravelData\DataCollection;

/**
 * The historical 1:1 ingest: every counterparty is (or becomes) a Lead, stakeholders are notified,
 * and the reply is whatever rule listens on `after-adding-message-to-channel`. Extracted verbatim
 * from ProcessWaSenderWebhookJob so the webhook job routes and this action owns the CRM shape.
 */
class CreateLeadMessageAction
{
    public function __construct(
        protected readonly ReceiverWebhook $receiver,
        protected readonly InboundMessage $inbound,
        protected readonly array $messageData,
        protected readonly bool $hijackSession = false,
    ) {
    }

    /**
     * The processed-message entry, or null when the payload carries nothing to file.
     */
    public function execute(): ?array
    {
        $messageContent = $this->messageData['message'] ?? [];
        $messageBody = $this->messageData['messageBody'] ?? null;

        $messageType = MessageTypeEnum::getMessageType($messageContent)->value;
        $isDocument = MessageTypeEnum::isDocumentType($messageType);
        $text = MessageTypeEnum::extractText($messageContent);
        $chatJid = $this->inbound->conversationJid;
        $isFromMe = $this->inbound->isFromMe;
        $messageId = $this->inbound->messageId;

        // A forwarded ad card arrives as an extendedTextMessage with no text and no media.
        if ($text === null && $messageBody === null && ! $isDocument) {
            return null;
        }

        [$lead, $shouldBlockAndPrivate] = $this->resolveLead($messageBody);

        $channels = new ConversationChannelService($this->receiver);
        $channel = $channels->getOrCreateChannel(jid: $chatJid, lead: $lead);
        $existingMessage = $channels->findMessageBySlug($messageId, $chatJid);

        if ($existingMessage) {
            $message = $existingMessage;
        } else {
            $messageTypeModel = new CreateMessageTypeAction(
                new MessageTypeInput(
                    $this->receiver->app->getId(),
                    0,
                    $messageType,
                    $messageType,
                )
            )->execute();

            $messageInput = new MessageInput(
                app: $this->receiver->app,
                company: $this->receiver->company,
                user: $this->receiver->user,
                type: $messageTypeModel,
                message: AiChatMessagePayload::from([
                    'content' => $text !== null && $text !== '' ? $text : $messageBody,
                    'from_me' => $isFromMe,
                    'from_ia' => false,
                    'raw_data' => $this->messageData,
                    'message_id' => $messageId,
                    'chat_jid' => $chatJid,
                ])->toArray(),
                is_public: 1,
                slug: ConversationChannelService::messageSlug($messageId, $chatJid),
                tags: [$chatJid]
            );

            $message = new CreateMessageAction($messageInput)->execute();
            if ($shouldBlockAndPrivate) {
                $message->setLock();
                $message->setPrivate();
            }
        }

        $this->chainConsecutiveImages($channel, $message);

        $message->addEntity($lead);
        $message->addTag('engagement');

        $channel->addMessage($message);

        if ($isFromMe === false) {
            new NotifyLeadStakeholdersService($lead)->onCustomerEngagement($message);
        }

        if ($isDocument) {
            new DownloadMessageFileAction(
                $channel,
                $message,
            )->execute();
        }

        if (! $isDocument) {
            $text = $message->message['raw_data']['message']['conversation'] ?? $message->message['raw_data']['message']['extendedTextMessage']['text'] ?? null;
            [$processDocument, $lastUnprocessedImageParentMessage] = $this->resolveDocumentTrigger($channel, $text);

            $channel->fireWorkflow(
                WorkflowEnum::AFTER_ADDING_MESSAGE_TO_CHANNEL->value,
                true,
                [
                    'message' => $message,
                    'user' => $message->user,
                    'app' => $message->app,
                    'company' => $message->company,
                    'process_document' => $processDocument,
                    'communication_channel' => 'whatsapp',
                    'text' => $text,
                    'lastMessageParentDocument' => $lastUnprocessedImageParentMessage,
                ]
            );
        }

        return [
            'message_id' => $message->getId(),
            'uuid' => $message->uuid,
            'channel_id' => $channel->getId(),
            'chat_jid' => $chatJid,
            'text' => $text,
            'is_from_me' => $isFromMe,
            'type' => $messageType,
        ];
    }

    /**
     * The lead this message belongs to, plus whether the message is our own API delivery — those
     * are filed locked and private so the UI does not show the agent's own reply as fresh inbound.
     *
     * @return array{0: Lead, 1: bool}
     *
     * @todo we need to create users for each user and associate with people
     */
    private function resolveLead(?string $messageBody): array
    {
        $people = new CreatePeopleFromJidAction(
            $this->receiver,
            $this->inbound->conversationJid,
            $this->inbound->isFromMe ? null : ($this->messageData['pushName'] ?? null),
            $this->hijackSession
        )->execute();

        $lead = $this->createLeadFromPeople($people);

        if (! $this->inbound->isFromMe) {
            $lead->set(LeadsEnumsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value, 'whatsapp');
            $lead->set(LeadsEnumsConfigurationEnum::IS_ENGAGEMENT->value, true);

            return [$lead, false];
        }

        if ($messageBody !== null && ! $lead->get(LeadsEnumsConfigurationEnum::FIRST_MESSAGE->value)) {
            $lead->set(LeadsEnumsConfigurationEnum::FIRST_MESSAGE->value, $messageBody);
            $lead->set('is_service_lead', 1);
        }

        // status 1 is our own API delivery; status 2 is a human typing on the phone itself, which
        // means the agent has to stand down.
        $status = (int) ($this->messageData['status'] ?? 0);

        if ($status === 2) {
            $lead->fireWorkflow(
                WorkflowEnum::TRIGGER_AI->value,
                true,
                [
                    'app' => $this->receiver->app,
                    'trigger_type' => TriggersEnum::HUMAN_TAKEOVER->value,
                ]
            );
        }

        return [$lead, $status === 1];
    }

    /**
     * A bare "dale"/"process" is how a DM user tells the agent to act on the photos they just
     * sent, so the trigger word resolves the newest image burst and reports whether it has already
     * been turned into a product.
     *
     * @return array{0: bool, 1: Message|null}
     */
    private function resolveDocumentTrigger(Channel $channel, ?string $text): array
    {
        $triggerWords = ['process', 'process document', 'dale', 'run'];

        if ($text === null || ! in_array(trim(strtolower($text)), $triggerWords, true)) {
            return [false, null];
        }

        $lastUnprocessedImageMessage = ChannelRepository::getChannelMessagesByVerb($channel, MessageTypeEnum::IMAGE->value)
            ->orderBy('messages.created_at', 'desc')
            ->first();

        if (! $lastUnprocessedImageMessage instanceof Message) {
            return [false, null];
        }

        $parent = $lastUnprocessedImageMessage->parent;

        return [! (bool) $parent?->get('created_product'), $parent];
    }

    /**
     * The pre-burst DM album heuristic: two images from the same channel within the window chain
     * under one parent. Kept as-is for DMs — live "dale/process" flows depend on the shape.
     */
    private function chainConsecutiveImages(Channel $channel, Message $message): void
    {
        $previousMessage = $channel->getPreviousMessage($message);
        $timeThresholdInSeconds = (int) ($this->receiver->configuration['time_threshold_in_seconds'] ?? 8);

        if ($previousMessage && $previousMessage->messageType->verb === MessageTypeEnum::IMAGE->value && $message->messageType->verb === MessageTypeEnum::IMAGE->value && $previousMessage->id !== $message->id) {
            $timeDifference = $message->created_at->diffInSeconds($previousMessage->created_at);

            if ($timeDifference < $timeThresholdInSeconds) {
                $previousMessageParent = $previousMessage->parent ?? $previousMessage;
                $message->update(['parent_id' => $previousMessageParent->id]);
            }
        }
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
                'Meta',
                true,
                'Meta'
            )
        )->execute();

        $receiverId = $this->receiver->configuration['receiver_id'] ?? null;

        if ($receiverId) {
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

        if ($pipelineId) {
            $pipeline = Pipeline::fromApp($people->app)
                ->fromCompany($people->company)
                ->where('id', $pipelineId)
                ->where('is_deleted', 0)
                ->first();
        }

        $leadData = new DataTransferObjectLead(
            app: $people->app,
            branch: $people->company->defaultBranch,
            user: $people->user,
            title: $people->name . ' WhatsApp Opp',
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
            'whatsapp',
            'ai-agent',
        ]);
        $lead->set('sub_source', 'Meta');
        $lead->set(LeadsEnumsConfigurationEnum::IS_FROM_WHATSAPP->value, true);

        return $lead;
    }
}
