<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Actions;

use Baka\Support\Str;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsEnumsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\ReceiverWebhookCall;

class CreateMessageFromEmailAction
{
    public function __construct(
        protected ReceiverWebhookCall $webhookRequest,
        protected ?Lead $lead = null,
    ) {
    }

    public function execute(): Message
    {
        $configUserId = $this->webhookRequest->receiverWebhook->configuration['user_id'] ?? null;
        $user = $configUserId === null ? $this->webhookRequest->receiverWebhook->user : Users::getById((int) $configUserId);
        $emailPlainText = $this->webhookRequest->payload['body-plain'] ?? null;
        $emailBodyHtml = $this->webhookRequest->payload['body-html'] ?? null;
        $text = trim($emailPlainText);
        $from = $this->webhookRequest->payload['sender'] ?? null;
        $channel = null;
        $messageTypeModel = (new CreateMessageTypeAction(
            new MessageTypeInput(
                $this->webhookRequest->receiverWebhook->app->getId(),
                0,
                'mailgun-email',
                'mailgun-email',
            )
        ))->execute();

        $messageInput = new MessageInput(
            app: $this->webhookRequest->receiverWebhook->app,
            company: $this->webhookRequest->receiverWebhook->company,
            user: $user,
            type: $messageTypeModel,
            message: [
                    'content' => $text,
                    'raw_data' => $text,
                    'message_id' => '--',
                    'chat_jid' => SessionChannelService::createChannelSlug('email', $from),
                    'from_email' => $from,
                    'subject' => $this->webhookRequest->payload['subject'] ?? null,
                    'from_me' => false,
            ],
            is_public: 1,
            //tags: [$to],
            //slug: Str::slug($text) . '-' . microtime()
        );

        if ($this->lead !== null) {
            $this->lead->set(LeadsEnumsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value, 'email');
            $channel = ChannelDto::from([
                      'apps' => $this->lead->app,
                      'companies' => $this->lead->company,
                      'users' => $this->lead->user,
                      'entity_id' => $this->lead->getId(),
                      'entity_namespace' => Lead::class,
                      'name' => 'Lead ' . $this->lead->getId() . ' Session',
                      'slug' => SessionChannelService::createChannelSlug(
                          $this->lead->get(LeadsEnumsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value),
                          $this->lead->email
                      ),
                  ]);
            $channel = (new CreateChannelAction($channel))->execute();

            $leadSystemModule = SystemModulesRepository::getByModelName(get_class($this->lead), $this->lead->app);
            $newMessage = new CreateMessageAction(
                $messageInput,
                $leadSystemModule,
                $this->lead->getId()
            )->execute();
        } else {
            $newMessage = new CreateMessageAction(
                $messageInput
            )->execute();
        }
        //$newMessage = $createMessageAction->execute();
        //$newMessage->addEntity($lead);

        if ($channel !== null) {
            $channel->addMessage($newMessage);

            $channel->fireWorkflow(
                WorkflowEnum::AFTER_ADDING_MESSAGE_TO_CHANNEL->value,
                true,
                [
                       'message' => $newMessage,
                       'user' => $newMessage->user,
                       'app' => $newMessage->app,
                       'company' => $newMessage->company,
                       'text' => $text,
                ]
            );
        }

        return $newMessage;
    }
}
