<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Workflows\Activities;

use Baka\Contracts\AppInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\PromptMine\Actions\MessageOrderFulfillmentAction;
use Kanvas\Connectors\PromptMine\Client as PromptClient;
use Kanvas\Connectors\PromptMine\Enums\MessageTypeEnum;
use Kanvas\Connectors\PromptMine\Notifications\ImageProcessingPushNotification;
use Kanvas\Connectors\PromptMine\Services\ImageFilterService;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Social\Messages\Actions\CheckMessagePostLimitAction;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Throwable;

class LLMMessageResponseActivity extends KanvasActivity
{
    public $tries = 2;
    protected ?AppInterface $app = null;

    public function execute(Message $message, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);
        $company = $this->getCompany($app, $message->company);
        $this->app = $app;

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::PROMPT_MINE,
            integrationOperation: function ($message, $app, $integrationCompany, $additionalParams) use ($params) {
                $prompt = $message->message['prompt'] ?? null;

                if (empty($prompt)) {
                    return [
                        'error' => 'Prompt is empty',
                    ];
                }

                $isTypeImage = isset($message->message['type']) && $message->message['type'] === MessageTypeEnum::IMAGE_FORMAT->value;

                $promptChannel = $message->channels->first();
                $isNotSafeForWork = false;

                if (! $isTypeImage) {
                    // Use the new chat functionality for text responses
                    $result = $this->generateChatResponse($message);
                    $response = $result['response'];
                    $chatHistory = $result['chat_history'];
                    $messageTypeKey = 'nugget';
                    $isNotSafeForWork = $result['nsfw_flag'] ?? false;
                } elseif ($isTypeImage && isset($message->message['ai_image']) && count($message->message['ai_image']) > 0) {
                    $result = $this->generateFilteredImageResponse($message, $params);
                    $response = $result['response'];
                    $chatHistory = $result['chat_history'];
                    $messageTypeKey = 'image';
                } else {
                    $result = $this->generateImageResponse($message);
                    $response = $result['response'];
                    $chatHistory = $result['chat_history'];
                    $messageTypeKey = 'image';
                    $isNotSafeForWork = $result['nsfw_flag'] ?? false;
                }

                $error = false;
                $errorReason = null;
                if (empty($response)) {
                    /* return [
                        'result' => false,
                        'error' => 'Response is empty',
                        'message' => $message->toArray(),
                        'message_id' => $message->id,
                    ]; */
                    $errorReason = 'Response is empty';
                    $error = true;
                }

                // $nuggetTitle = $this->generateTitleByPrompt($prompt);

                $messageInput = [
                    'message' => [
                        $messageTypeKey => $response,
                        'type' => $isTypeImage ? MessageTypeEnum::IMAGE_FORMAT->value : MessageTypeEnum::TEXT_FORMAT->value,
                        'chat_history' => $chatHistory, // Include chat history
                        'nsfw_flag' => $isNotSafeForWork ?? false,
                        'error' => $error,
                        'error_reason' => $error ? $result['message'] ?? $errorReason : null,
                        'nsfw_reason' => $isNotSafeForWork ? $result['message'] ?? 'Content flagged as NSFW' : null,
                    ],
                    'reactions_count' => 0,
                    'comments_count' => 0,
                    'total_liked' => 0,
                    'total_disliked' => 0,
                    'total_saved' => 0,
                    'total_shared' => 0,
                    'ip_address' => '127.0.0.1',
                    'parent_id' => $message->id,
                    'is_public' => 1,
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

                if ($promptChannel && empty($promptChannel->title) && isset($message->message['title']) && ! empty($message->message['title'])) {
                    $channelName = $this->cleanChannelTitle($message->message['title']);
                    $promptChannel->name = $channelName;
                    $promptChannel->title = $channelName;
                    $promptChannel->update();
                }

                $message->is_public = 0;
                $message->save();

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

    private function cleanChannelTitle(string $title): string
    {
        // Remove markdown formatting
        $cleanTitle = preg_replace('/\*\*([^*]+)\*\*/', '$1', $title);

        // Extract just the title part if it follows the pattern "**Title:** ActualTitle"
        if (preg_match('/\*\*Title:\*\*\s*([^\n\r*]+)/', $cleanTitle, $matches)) {
            $cleanTitle = trim($matches[1]);
        }

        // If it still contains image prompt markers, extract just the title before the prompt
        if (Str::contains($cleanTitle, '/imagine')) {
            $parts = explode('/imagine', $cleanTitle);
            $cleanTitle = trim($parts[0]);
        }

        // Remove any remaining markdown or special characters
        $cleanTitle = preg_replace('/[*#`\[\]]+/', '', $cleanTitle);

        // Remove extra whitespace and newlines
        $cleanTitle = preg_replace('/\s+/', ' ', $cleanTitle);
        $cleanTitle = trim($cleanTitle);

        // If title is still empty or too generic, use a fallback
        if (empty($cleanTitle) || strlen($cleanTitle) < 3) {
            $cleanTitle = 'AI Generated Content';
        }

        // Truncate to 190 characters to leave some buffer
        if (strlen($cleanTitle) > 190) {
            $cleanTitle = substr($cleanTitle, 0, 187) . '...';
        }

        return $cleanTitle;
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

        try {
            // Use PromptMine client for chat response with AI model configuration
            $promptClient = new PromptClient($message->app);
            $apiResponse = $promptClient->generateChatResponse($messages, $aiModel);

            // Extract response text and update chat history
            $responseText = $promptClient->extractChatResponseText($apiResponse);
            $fullConversation = $promptClient->getFullConversation($messages, $apiResponse);
        } catch (ClientException | ServerException $e) {
            $errorBody = $e->getResponse()->getBody()->getContents();
            $isNotSafeForWork = Str::contains($errorBody, ['NSFW', 'blocked']);

            $endViaList = array_map(
                [NotificationChannelEnum::class, 'getNotificationChannelBySlug'],
                ['push', 'mail']
            );
            $errorProcessingImageNotification = new ImageProcessingPushNotification(
                user: $message->user,
                entity: $message,
                message: 'Your image prompt was flagged as not safe for work and could not be processed.',
                title: 'Image Processing Error',
                via: $endViaList,
                templates: [
                    'email_template' => 'email-new-message-nugget',
                    'push_template' => 'push-new-message-nugget',
                ],
            );

            $errorProcessingImageNotification->setData([
                'destination_id' => $message->getId(),
                'destination_type' => 'USER',
                'destination_event' => 'FOLLOWING',
            ]);
            $message->user->notify($errorProcessingImageNotification);

            //return [$isNotSafeForWork ? $message->app->get('NSFW_IMAGE_URL') : ''];
            return [
                'response' => 'Your prompt was flagged as not safe for work and could not be processed.',
                'chat_history' => [],
                'message' => Str::isJson($errorBody) ? json_decode($errorBody, true) : $errorBody,
                'nsfw_flag' => true,
            ];
        }


        return [
            'response' => str_replace(['```', 'json'], '', $responseText ?? ''),
            'chat_history' => $fullConversation,
            'ai_model_used' => $aiModel, // Include which model was used
        ];
    }

    private function generateFilteredImageResponse(Message $message, array $params): array
    {
        $prompt = $message->message['prompt'] ?? null;
        $imageFilterService = new ImageFilterService(
            app: $this->app,
            entity: $message,
            params: $params,
        );

        $imageFilterResult = $imageFilterService->execute();

        if (isset($imageFilterResult['result']) && $imageFilterResult['result'] === false) {
            throw new \Exception('Image filtering failed: ' . ($imageFilterResult['message'] ?? 'Unknown error'));
        }

        // Get existing chat history from parent message or create new conversation
        $chatHistory = $this->getChatHistory($message);

        // Add the new user message to the conversation
        $messages = $chatHistory;
        $messages[] = [
            'role' => 'user',
            'content' => $prompt,
        ];

        $promptClient = new PromptClient($message->app);
        $fullConversation = $promptClient->getFullConversation($messages, $imageFilterResult);

        return [
            'response' => $imageFilterResult['image_url'] ?? '',
            'chat_history' => $fullConversation,
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

    private function generateImageResponse(Message $message): array
    {
        new MessageOrderFulfillmentAction($message)->execute('image');

        $promptClient = new PromptClient($message->app);
        $prompt = $message->message['prompt'] ?? null;
        $params = [];

        $provider = (string) ($message->message['ai_model']['key'] ?? 'dalle3');
        $model = (string) ($message->message['ai_model']['value'] ?? 'dall-e-3');

        if ($message->message['type'] === MessageTypeEnum::IMAGE_FORMAT->value) {
            if (isset($message->message['platform']) && $message->message['platform'] === 'android') {
                $params['safety_tolerance'] = 1;
                $params['enable_safety_checker'] = true;
            }

            $channel = $message->channels?->first();
            $previousChatResponse = $channel !== null ? $channel->getPreviousMessage($message) : null;

            if ($previousChatResponse instanceof Message && $previousChatResponse->isRoot()) {
                $previousChatResponseMessage = $previousChatResponse->message['prompt'] ?? null;
                $previousChatMessageChildren = $previousChatResponse->children()?->first();
                $params['previousImageUrl'] = $previousChatMessageChildren !== null ? $previousChatMessageChildren->message['image'] : null;
                $params['previousPrompts'] = $previousChatResponseMessage ? [$previousChatResponseMessage] : [];
                $params['subscribe'] = true;
            }
        }
        $imageLimitValidation = $this->validateImageLimit($message);

        if ($imageLimitValidation !== null) {
            return $imageLimitValidation;
        }

        try {
            $generateImage = $previousChatResponse === null ? $promptClient->generateImage(
                provider: $provider,
                model: $model,
                prompt: $prompt,
                params: $params
            ) : $promptClient->continueImageChat(
                //provider: $provider,
                previousImageUrl: $params['previousImageUrl'] ?? '',
                previousPrompts: $params['previousPrompts'] ?? [],
                //model: $model,
                model: $this->app->get('default-image-edit-model') ?? 'fal-ai/flux-kontext/dev',
                newPrompt: $prompt,
                subscribe: $params['subscribe'] ?? true,
                //conversationId: $previousChatResponse->message['conversation_id'],
                //messageId: $previousChatResponse->message['message_id'],
                //params: $params
            );
        } catch (ClientException | ServerException $e) {
            $errorBody = $e->getResponse()->getBody()->getContents();
            $isNotSafeForWork = Str::contains($errorBody, ['NSFW', 'blocked']);

            $endViaList = array_map(
                [NotificationChannelEnum::class, 'getNotificationChannelBySlug'],
                ['push', 'mail']
            );
            $errorProcessingImageNotification = new ImageProcessingPushNotification(
                user: $message->user,
                entity: $message,
                message: 'Your image prompt was flagged as not safe for work and could not be processed.',
                title: 'Image Processing Error',
                via: $endViaList,
                templates: [
                    'email_template' => 'email-new-message-nugget',
                    'push_template' => 'push-new-message-nugget',
                ],
            );

            $errorProcessingImageNotification->setData([
                'destination_id' => $message->getId(),
                'destination_type' => 'USER',
                'destination_event' => 'FOLLOWING',
            ]);
            $message->user->notify($errorProcessingImageNotification);

            //return [$isNotSafeForWork ? $message->app->get('NSFW_IMAGE_URL') : ''];
            return [
                'response' => $message->app->get('NSFW_IMAGE_URL'),
                'chat_history' => [],
                'message' => Str::isJson($errorBody) ? json_decode($errorBody, true) : $errorBody,
                'nsfw_flag' => true,
            ];
        }

        $parseResponse = $previousChatResponse === null ? 'extractImageUrl' : 'extractImageChatUrl';
        $generateResponse = (string) $promptClient->{$parseResponse}(
            $generateImage
        );

        $response = [
            'image_url' => $generateResponse,
        ];

        // Get existing chat history from parent message or create new conversation
        $chatHistory = $this->getChatHistory($message);

        // Add the new user message to the conversation
        $messages = $chatHistory;
        $messages[] = [
            'role' => 'user',
            'content' => $prompt,
        ];

        $promptClient = new PromptClient($message->app);
        $fullConversation = $promptClient->getFullConversation($messages, $response);

        return [
            'response' => $response['image_url'],
            'chat_history' => $fullConversation,
        ];
    }

    public function validateImageLimit(Message $message): ?array
    {
        try {
            (new CheckMessagePostLimitAction(
                message: $message,
                //messageTypeId: MessageType::fromApp($message->app)->where('verb', 'prompt')->firstOrFail()->getId(),
                messageJsonFilters: ['type' => 'image-format']
            ))->execute();

            $user = Users::getById($message->users_id);
            $user->set('images_generated', ($user->get('images_generated', 0) + 1), true);
        } catch (Throwable $e) {
            if (! Str::contains($e->getMessage(), 'Your daily limit has been reached')) {
                report($e);
            }

            try {
                $endViaList = array_map(
                    [NotificationChannelEnum::class, 'getNotificationChannelBySlug'],
                    ['push', 'mail']
                );
                $errorProcessingImageNotification = new ImageProcessingPushNotification(
                    user: $message->user,
                    entity: $message,
                    message: 'You have reached your daily image generation limit.',
                    title: 'Daily Limit Reached',
                    via: $endViaList,
                    templates: [
                        'email_template' => 'email-new-message-nugget',
                        'push_template' => 'push-new-message-nugget',
                    ],
                );

                //send to the user profile when it fails
                $errorProcessingImageNotification->setData([
                    'destination_id' => $message->getId(),
                    'destination_type' => 'USER',
                    'destination_event' => 'FOLLOWING',
                ]);
                $message->user->notify($errorProcessingImageNotification);
            } catch (Throwable $e) {
                report($e);
            }

            $useOnlyImageResponse = (bool) ($message->app->get('use_only_image_response') ?? false);

            return $useOnlyImageResponse ? $message->app->get('LIMIT_IMAGE_URL') :
                [
                    'error' => 'You have reached your daily image generation limit.',
                    'image_url' => $message->app->get('LIMIT_IMAGE_URL') ?? '',
                    'limit' => $message->app->get('message-post-limit') ?? 0,
                    'flag' => true,
                ];
        }

        return null;
    }

    private function generateTitleByPrompt(string $prompt): string
    {
        $response = Prism::text()
            ->using(Provider::Gemini, 'gemini-2.0-flash')
            ->withPrompt('Generate a short concise title from this prompt: ' . $prompt . '.Choose just one title, dont give me suggestions')
            ->generate();

        return trim(str_replace(['```', 'json'], '', $response->text));
    }

    /**
     * Get the company for this workflow
     */
    protected function getCompany(AppInterface $app, Model $entity): Companies
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
