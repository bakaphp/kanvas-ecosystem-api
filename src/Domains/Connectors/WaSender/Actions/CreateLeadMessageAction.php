<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Kanvas\Communication\Messages\Actions\AnnounceInboundTurnAction;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Jobs\RespondToInboundMessageJob;
use Kanvas\Connectors\WaSender\Actions\Concerns\DownloadsMessageMedia;
use Kanvas\Connectors\WaSender\DataTransferObject\InboundMessage;
use Kanvas\Connectors\WaSender\Enums\MessageTypeEnum;
use Kanvas\Connectors\WaSender\Handlers\WaSenderHandler;
use Kanvas\Connectors\WaSender\Services\ConversationChannelService;
use Kanvas\Guild\Customers\Actions\ProcessInboundConsentAction;
use Kanvas\Guild\Customers\DataTransferObject\ConsentOutcome;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\OpenLeadFromConversationAction;
use Kanvas\Guild\Leads\Actions\SendOptOutConfirmationAction;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsEnumsConfigurationEnum;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Guild\Leads\Services\NotifyLeadStakeholdersService;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Intelligence\Triggers\Events\LeadAiTriggeredEvent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Channels\Repositories\ChannelRepository;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\ReceiverWebhook;

/**
 * The lead 1:1 ingest: every counterparty is (or becomes) a Lead and stakeholders are notified. The
 * message is stamped with the number's connection, and the agent that owns that number answers
 * (RespondToInboundMessageJob). `after-adding-message-to-channel` still fires for a rule a tenant wrote.
 */
class CreateLeadMessageAction
{
    use DownloadsMessageMedia;

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

        $messageType = MessageTypeEnum::getMessageType($messageContent);
        $isDocument = $messageType->isDocumentType();
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
            $messageInput = new MessageInput(
                app: $this->receiver->app,
                company: $this->receiver->company,
                user: $this->receiver->user,
                type: MessageTypeService::getOrCreate($this->receiver->app, $messageType->value),
                message: [
                    ...AiChatMessagePayload::from([
                        'content' => $text !== null && $text !== '' ? $text : $messageBody,
                        'from_me' => $isFromMe,
                        'from_ia' => false,
                        'raw_data' => $this->messageData,
                        'message_id' => $messageId,
                        'chat_jid' => $chatJid,
                    ])->toArray(),
                    ...$this->communicationRoute($chatJid),
                ],
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

        // The message is still filed above — a stop request is part of the record — but nothing
        // downstream of here may run for it: no media fetch, and above all no agent turn.
        $consentOutcome = $this->processConsent($lead, $isFromMe, $text ?? $messageBody);
        $haltAgentTurn = $consentOutcome?->shouldHaltAgentTurn() ?? false;

        if ($isFromMe === false && ! $haltAgentTurn) {
            $lead->addTag('engaged');
        }

        if ($haltAgentTurn) {
            $message->addTag('consent-stop');

            if ($consentOutcome->applied) {
                new SendOptOutConfirmationAction(
                    $lead,
                    LeadCommunicationChannelEnum::WHATSAPP->value,
                )->execute();
            }
        }

        if ($isDocument && ! $haltAgentTurn) {
            $this->downloadMedia($message);
        }

        // Only the album head fires a workflow, avoiding a separate reply for every photo.
        $firesAgentTurn = ! $haltAgentTurn && (! $isDocument || $message->parent_id === null);

        if ($firesAgentTurn) {
            [$processDocument, $lastUnprocessedImageParentMessage] = $isDocument
                ? [false, null]
                : $this->resolveDocumentTrigger($channel, $text);
            $prompt = $processDocument
                ? $this->announceDocumentProcessing($message, $lastUnprocessedImageParentMessage)
                : null;

            // The agent that owns the number answers; no rule has to exist for it.
            new AnnounceInboundTurnAction(
                $channel,
                $message,
                (string) $text,
                [
                    'process_document' => $processDocument,
                    'communication_channel' => 'whatsapp',
                    'lastMessageParentDocument' => $lastUnprocessedImageParentMessage,
                ],
                $prompt
            )->execute();
        }

        return array_merge([
            'message_id' => $message->getId(),
            'uuid' => $message->uuid,
            'channel_id' => $channel->getId(),
            'chat_jid' => $chatJid,
            'text' => $text,
            'is_from_me' => $isFromMe,
            'type' => $messageType->value,
        ], $consentOutcome?->toArray() ?? []);
    }

    /**
     * What a reply reads to leave through the same number, and what a turn queued before the
     * connection was switched off checks to stand down. Empty until the number is a connection.
     *
     * @return array{communication?: array<string, mixed>}
     */
    private function communicationRoute(string $chatJid): array
    {
        $connection = WaSenderHandler::connectionFor($this->receiver->app, $this->receiver->company);

        if ($connection === null) {
            return [];
        }

        return ['communication' => [
            'connection_id' => $connection->getId(),
            'channel' => ChannelTypeEnum::WHATSAPP->value,
            'thread' => str_replace('@s.whatsapp.net', '', $chatJid),
            'activation_version' => $connection->activation_version,
        ]];
    }

    /**
     * "dale" / "process" asks for the photos just sent to be acted on: whatever listens on the
     * album runs now, and the agent is told so in place of the bare trigger word.
     */
    private function announceDocumentProcessing(Message $message, ?Message $album): ?string
    {
        if ($album === null) {
            return null;
        }

        $album->fireWorkflow(WorkflowEnum::DURING_WORKFLOW->value, true, [
            'app' => $message->app,
            'company' => $message->company,
        ]);

        return 'Keep record we just processed files under the parent message '
            . $album->id . ' so we can reference it to process '
            . 'later and return the msg id so the I know about it';
    }

    /**
     * WhatsApp has no carrier-level opt-out to lean on the way SMS does — Meta requires the business
     * to honor stop requests itself, including ones made on other channels.
     */
    private function processConsent(Lead $lead, bool $isFromMe, ?string $text): ?ConsentOutcome
    {
        $people = $lead->people;

        if ($isFromMe || $people === null) {
            return null;
        }

        return new ProcessInboundConsentAction(
            people: $people,
            sourceChannel: LeadCommunicationChannelEnum::WHATSAPP->value,
            body: $text,
            lead: $lead,
            contactValue: $this->inbound->conversationJid,
        )->execute();
    }

    /**
     * The lead this message belongs to, plus whether the message is our own API delivery — those
     * are filed locked and private so the UI does not show the agent's own reply as fresh inbound.
     *
     * @return array{0: Lead, 1: bool}
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
            LeadAiTriggeredEvent::announce($lead, TriggersEnum::HUMAN_TAKEOVER->value);
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

        if ($previousMessage === null
            || $previousMessage->id === $message->id
            || $previousMessage->messageType->verb !== MessageTypeEnum::IMAGE->value
            || $message->messageType->verb !== MessageTypeEnum::IMAGE->value
        ) {
            return;
        }

        if ($message->created_at->diffInSeconds($previousMessage->created_at) < $timeThresholdInSeconds) {
            $message->update(['parent_id' => ($previousMessage->parent ?? $previousMessage)->id]);
        }
    }

    protected function createLeadFromPeople(People $people): Lead
    {
        $activeLead = LeadsRepository::getPeopleActiveLead($people);
        if ($activeLead) {
            return $activeLead;
        }

        $lead = new OpenLeadFromConversationAction(
            people: $people,
            source: 'Meta',
            title: $people->name . ' WhatsApp Opp',
            filing: $this->receiver->configuration ?? []
        )->execute();
        $lead->addTags([
            'whatsapp',
            'ai-agent',
        ]);
        $lead->set('sub_source', 'Meta');
        $lead->set(LeadsEnumsConfigurationEnum::IS_FROM_WHATSAPP->value, true);

        return $lead;
    }
}
