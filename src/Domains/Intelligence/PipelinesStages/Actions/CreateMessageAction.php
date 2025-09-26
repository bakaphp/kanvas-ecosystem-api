<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\PipelinesStages\Actions;

use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Messages\Actions\CreateMessageAction as CreateSocialMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;

class CreateMessageAction
{
    public function __construct(protected string $prompt, protected Session $session)
    {
    }

    public function execute(): string
    {
        $response = Prism::text()
            ->using(Provider::Gemini, 'gemini-2.5-pro')
            ->withPrompt($this->prompt)
            ->asText();
        $messageType = MessageType::firstOrCreate([
            'apps_id' => $this->session->apps_id,
            'languages_id' => 1,
            'name' => 'AI Generated Message',
        ]);
        $messageInput = MessageInput::from([
            'app' => $this->session->app,
            'company' => $this->session->company,
            'user' => $this->session->agent->user,
            'type' => $messageType,
            'message' => $response->text,
        ]);
        $message = new CreateSocialMessageAction($messageInput)->execute();
        $this->session->channel->addMessage($message);

        return $response->text;
    }
}
