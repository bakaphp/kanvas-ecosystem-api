<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\Actions\MarkLeadMessagesAsRespondedAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;

class BaseAgentResponderAction
{
    protected string $messageTypeVerb = 'text';
    protected string $communicationChannel;

    public function __construct(
        protected Channel $channel,
        protected Message $message,
        protected Agent $agent,
        protected ?Session $session = null,
    ) {
        $lead = $this->session?->entity() ?? $this->message->entity();

        if ($lead === null) {
            throw new Exception('No lead found for AI agent');
        }

        $aiMode = $lead->get('ai_mode');
        if ($aiMode == IntelligenceModeEnum::OFF->value) {
            throw new Exception('Ai Agent Off for this lead');
        }
    }

    protected function createMessage(
        string $text,
        string $to,
        Message $message,
        Channel $channel,
        ?string $from = null
    ): Message {
        $user = $message->user;
        $agentUser = $this->channel->company->get('ai-agent-user-id');
        if ($agentUser !== null) {
            $user = Users::getById((int) $agentUser);
        }
        $type = $this->getMessageType($message->app);
        $messageInput = new MessageInput(
            app: $message->app,
            company: $message->company,
            user: $user,
            message: [
                    'content' => $text,
                    'raw_data' => $text,
                    'message_id' => '--',
                    'chat_jid' => $to,
                    'from_me' => true,
                    'from_ia' => true,
            ],
            is_public: 1,
            tags: [$to],
            type: $type,
            //slug: Str::slug($text) . '-' . microtime()
        );

        $newMessage = new CreateMessageAction($messageInput);
        $newMessage->runWorkflow = false;
        $newMessage = $newMessage->execute();

        $newMessage->set('communicationChannel', $this->communicationChannel);
        $newMessage->set('from_number', $from);

        if ($message->entity() instanceof Model) {
            $newMessage->addEntity($message->entity());
        }

        // $isWithinWorkingHours = $message->entity()->company->isWithinWorkingHours(now());

        // $agentSupportMode = $isWithinWorkingHours
        //     && $this->session->entity()?->get('ai_mode') === IntelligenceModeEnum::SUPPORT->value;

        // if ($agentSupportMode) {
        //     $newMessage->setLock();
        //     $newMessage->setPrivate();
        // }

        $newMessage->fireWorkflow(
            WorkflowEnum::CREATED->value,
            true,
            [
                 'app' => $newMessage->app,
             ]
        );
        $channel->addMessage($newMessage);

        $lead = $message->entity();
        if ($lead instanceof Lead) {
            new MarkLeadMessagesAsRespondedAction($lead, $newMessage)->execute();
        }

        return $newMessage;
    }

    protected function hijackMessagePhone(string $channelId): string
    {
        if ($this->agent->company->get('allow_session_hijack', false)
          && $this->agent->company->get('overwrite_phone_number') !== null
        ) {
            $overwriteConfig = $this->agent->company->get('overwrite_phone_number');
            $originalRemoteJid = $channelId;

            // Reverse lookup: hijacked -> original
            $reverseMapping = array_flip($overwriteConfig);
            if (isset($reverseMapping[$originalRemoteJid])) {
                return $reverseMapping[$originalRemoteJid];
            }
        }

        return $channelId;
    }

    public function execute(array $params = []): array
    {
        return [];
    }

    public function getMessageType(Apps $app): MessageType
    {
        return MessageTypeService::getOrCreate($app, $this->messageTypeVerb);
    }
}
