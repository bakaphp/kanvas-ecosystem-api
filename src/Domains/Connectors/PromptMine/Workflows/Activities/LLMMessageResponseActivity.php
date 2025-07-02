<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\PromptMine\Client as PromptClient;
use Kanvas\Connectors\PromptMine\Enums\MessageTypeEnum;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;

class LLMMessageResponseActivity extends KanvasActivity
{
    public $tries = 2;

    public function execute(Message $message, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $company = $this->getCompany($app, $message->company);

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::PROMPT_MINE,
            integrationOperation: function ($message, $app, $integrationCompany, $additionalParams) {
                $prompt = $message->message['prompt'] ?? null;

                if (empty($prompt)) {
                    return [
                        'error' => 'Prompt is empty',
                    ];
                }

                $isTypeImage = isset($message->message['type']) && $message->message['type'] === MessageTypeEnum::IMAGE_FORMAT->value;

                $promptChannel = $message->channels->first();

                if (! $isTypeImage) {
                    // Use the new chat functionality for text responses
                    $result = $this->generateChatResponse($message);
                    $response = $result['response'];
                    $chatHistory = $result['chat_history'];
                    $messageTypeKey = 'nugget';
                } else {
                    $response = $this->generateImageResponse($message);
                    $chatHistory = []; // No chat history for images
                    $messageTypeKey = 'image';
                }

                if (empty($response)) {
                    return [
                        'result' => false,
                        'error' => 'Response is empty',
                        'message' => $message->toArray(),
                        'message_id' => $message->id,
                    ];
                }

                $nuggetTitle = $this->generateTitleByPrompt($prompt);

                $messageInput = [
                    'message' => [
                        'title' => $nuggetTitle,
                        $messageTypeKey => $response,
                        'type' => $isTypeImage ? MessageTypeEnum::IMAGE_FORMAT->value : MessageTypeEnum::TEXT_FORMAT->value,
                        'chat_history' => $chatHistory, // Include chat history
                    ],
                    'reactions_count' => 0,
                    'comments_count' => 0,
                    'total_liked' => 0,
                    'total_disliked' => 0,
                    'total_saved' => 0,
                    'total_shared' => 0,
                    'ip_address' => '127.0.0.1',
                    'parent_id' => $message->id,
                ];

                $messageTypeDto = MessageTypeInput::from([
                    'apps_id' => $app->getId(),
                    'name' => 'chat-response',
                    'verb' => 'chat-response',
                ]);
                $messageType = (new CreateMessageTypeAction($messageTypeDto))->execute();

                $createMessage = (new CreateMessageAction(
                    MessageInput::fromArray(
                        $messageInput,
                        $message->user,
                        $messageType,
                        $message->company,
                        $app
                    ),
                ))->execute();

                if ($promptChannel && empty($promptChannel->title)) {
                    $promptChannel->name = $message->message['title'] ?? $nuggetTitle;
                    $promptChannel->title = $promptChannel->name;
                    $promptChannel->update();
                }

                return [
                    'result' => true,
                    'child_message' => $createMessage->toArray(),
                    'child_message_id' => $createMessage->id,
                    'message' => $message->toArray(),
                    'message_id' => $message->id,
                    'response' => $response,
                    'chat_history' => $chatHistory,
                ];
            },
            company: $company,
        );
    }

    private function generateChatResponse(Message $message): array
    {
        $prompt = $message->message['prompt'] ?? null;

        if (empty($prompt)) {
            return ['response' => '', 'chat_history' => []];
        }

        // Get AI model configuration from message
        $aiModel = $message->message['ai_model'] ?? [
            'key' => 'gemini',
            'value' => 'gemini-2.0-flash',
            'name' => 'Gemini 2.0 Flash',
        ];

        // Get existing chat history from parent message or create new conversation
        $chatHistory = $this->getChatHistory($message);

        // Add the new user message to the conversation
        $messages = $chatHistory;
        $messages[] = [
            'role' => 'user',
            'content' => $prompt,
        ];

        // Use PromptMine client for chat response with AI model configuration
        $promptClient = new PromptClient($message->app);
        $apiResponse = $promptClient->generateChatResponse($messages, $aiModel);

        // Extract response text and update chat history
        $responseText = $promptClient->extractChatResponseText($apiResponse);
        $fullConversation = $promptClient->getFullConversation($messages, $apiResponse);

        return [
            'response' => str_replace(['```', 'json'], '', $responseText ?? ''),
            'chat_history' => $fullConversation,
            'ai_model_used' => $aiModel, // Include which model was used
        ];
    }

    private function getChatHistory(Message $message): array
    {
        $channel = $message->channels->first();

        $previousMessage = $channel ? $channel->getPreviousMessage($message) : null;

        if ($previousMessage === null) {
            // If no previous message, return empty chat history
            return [];
        }

        // Check if previous message has children and if the first child has chat history
        $firstChild = $previousMessage->children()->first();

        if ($firstChild && isset($firstChild->message['chat_history']) && is_array($firstChild->message['chat_history'])) {
            return $firstChild->message['chat_history'];
        }

        // Return empty array for new conversations or when no valid chat history is found
        return [];
    }

    private function generateImageResponse(Message $message): string
    {
        $promptClient = new PromptClient($message->app);
        $prompt = $message->message['prompt'] ?? null;

        $provider = (string) ($message->message['ai_model']['key'] ?? 'dalle3');
        $model = (string) ($message->message['ai_model']['value'] ?? 'dall-e-3');

        //return $promptClient->extractImageUrl($promptClient->generateImageWithIdeogram($prompt));
        return (string) $promptClient->extractImageUrl(
            $promptClient->generateImage(
                provider: $provider,
                model: $model,
                prompt: $prompt,
                key: 'text-to-image'
            )
        );
    }

    private function generateTitleByPrompt(string $prompt): string
    {
        $response = Prism::text()
            ->using(Provider::Gemini, 'gemini-2.0-flash')
            ->withPrompt('Generate a short concise title from this prompt: ' . $prompt . '.Choose just one title, dont give me suggestions')
            ->generate();

        return str_replace(['```', 'json'], '', $response->text);
    }

    /**
     * Get the company for this workflow
     */
    protected function getCompany(AppInterface $app, Model $entity): object
    {
        $defaultAppCompanyBranch = $app->get(AppSettingsEnums::GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY->getValue());

        try {
            $branch = CompaniesBranches::getById($defaultAppCompanyBranch);

            return $branch->company;
        } catch (ModelNotFoundException $e) {
            return $entity->company;
        }
    }
}
