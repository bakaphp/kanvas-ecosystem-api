<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Chat;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;
use NeuronAI\Agent\AgentHandler;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\UserMessage;
use Throwable;

class RunNeuronChatAction
{
    public function __construct(
        protected readonly Agent $agent,
        protected readonly ?Session $session,
        protected readonly string $message,
        protected readonly Apps $app,
        protected readonly Users $user,
        protected readonly mixed $handler,
        protected readonly array $images = []
    ) {
    }

    public function execute(): string
    {
        $sessionId = $this->session?->uuid ?? '';

        $message = new UserMessage($this->message);
        foreach ($this->images as $image) {
            $message->addContent(
                new ImageContent(
                    content: base64_encode(file_get_contents($image)),
                    sourceType: SourceType::BASE64,
                    mediaType: 'image/png'
                )
            );
        }

        try {
            $responseContent = $this->handler->chat($message);
        } catch (Throwable $e) {
            report($e);

            throw $e;
        }

        $message = $responseContent instanceof AgentHandler
            ? $responseContent->getMessage()
            : $responseContent;
        $content = $message->getContent() ?? '';
        $response = ChatHelper::extractTextFromResponse($content);
        new KanvasConversationStore()->logTurn(
            userId: $this->user->getId(),
            sessionId: $sessionId,
            agentClass: get_class($this->handler),
            userMessage: $this->message,
            assistantResponse: $response,
        );

        return $content;
    }
}
