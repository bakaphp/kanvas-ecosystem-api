<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;

class BaseAgentResponderAction
{
    protected string $messageTypeVerb = 'text';

    public function __construct(
        protected Channel $channel,
        protected Message $message,
        protected Agent $agent,
        protected ?Session $session = null,
    ) {
    }

    protected function createMessage(string $text, string $to, Message $message, Channel $channel): Message
    {
        $user = $message->user;
        $agentUser = $this->channel->app->get('kanvas_agent_user_id');
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

        $newMessage = new CreateMessageAction($messageInput)->execute();
        if ($message->entity() instanceof Model) {
            $newMessage->addEntity($message->entity());
        }
        $channel->addMessage($newMessage);

        if ($this->session->entity()?->get('ai_mode') === IntelligenceModeEnum::SUPPORT->value) {
            $newMessage->setLock();
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

    public function getMessageType(Apps $apps): MessageType
    {
        $messageTypeInput = MessageTypeInput::from([
            'apps_id' => $apps->getId(),
            'verb' => $this->messageTypeVerb,
            'name' => Str::title($this->messageTypeVerb),
        ]);

        $messageTypeAction = new CreateMessageTypeAction($messageTypeInput);

        return $messageTypeAction->execute();
    }
}
