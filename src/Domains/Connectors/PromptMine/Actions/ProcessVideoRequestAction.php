<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Kanvas\Social\Messages\Models\Message;

class ProcessVideoRequestAction
{
    protected bool $isGoogleService = false;

    public function __construct(
        protected Message $entity,
        protected AppInterface $app,
        protected array $params = []
    ) {
    }

    public function execute(): array
    {
        // Extract video model dynamically from message - use the full value as is
        $videoModel = $this->entity->message['ai_model']['value'] ?? 'fal-ai/veo3';
        $videoType = $this->entity->message['type'] ?? 'video-format';

        // Determine if it's text-to-video or image-to-video based on hasFiles flag
        $isImageToVideo = isset($this->entity->message['hasFiles']) && $this->entity->message['hasFiles'] === true;

        // Construct the API URL based on video type
        $baseApiUrl = $this->entity->app->get('PROMPT_VIDEO_API_URL');
        $videoKey = $isImageToVideo ? 'fal-ai/image-to-video' : 'fal-ai/text-to-video';
        $isGoogleService = false;

        /**
         * if its google use the specific api route
         */
        if (Str::contains($videoModel, 'veo')) {
            $videoKey = str_replace('fal-ai/', 'google/', $videoKey);
            $videoModel = str_replace('fal-ai/', '', $videoModel);
            $isGoogleService = true;
            $this->isGoogleService = true;
        }

        $apiUrl = $baseApiUrl . '/api/v2/video/' . $videoKey;

        if (empty($apiUrl) || empty($baseApiUrl)) {
            return [
                'result' => false,
                'message' => 'Video API URL not configured',
            ];
        }

        try {
            // Check if we already have a request_id (in case of retry)
            $existingRequestId = $this->entity->message['video_request_id'] ?? null;

            if ($existingRequestId) {
                return [
                    'result' => true,
                    'model' => $videoModel,
                    'request_id' => $existingRequestId,
                    'message' => 'Video processing request already submitted. Processing continues asynchronously.',
                    'message_id' => $this->entity->getId(),
                    'status' => 'IN_QUEUE',
                ];
            }

            if ($isImageToVideo) {
                // Process image-to-video
                //$messageFiles = $this->entity->getFiles();
                $messageFiles = $this->getFilesWithRetry($this->entity);
                if ($messageFiles->isEmpty()) {
                    return [
                        'result' => false,
                        'message' => 'Message does not have any files for image-to-video processing',
                    ];
                }

                $videoUrlsArray = $messageFiles->map(fn ($file) => $file->url)->toArray();
                $results = $this->submitImageToVideo($videoUrlsArray, $videoModel, $apiUrl);
                $requestId = $results['request_id'] ?? null;
            } else {
                // Process text-to-video
                $results = $this->submitTextToVideo($videoModel, $apiUrl);
                $requestId = $results['request_id'] ?? null;
            }

            if ($requestId === null) {
                return [
                    'result' => false,
                    'model' => $videoModel,
                    'message' => 'Failed to submit video processing request',
                ];
            }

            // Store the request ID for tracking
            $messageCopy = $this->entity->message;
            $messageCopy['video_request_id'] = $requestId;
            $messageCopy['video_processing_status'] = 'IN_QUEUE';
            $this->entity->message = $messageCopy;
            $this->entity->save();

            return [
                'result' => true,
                'model' => $videoModel,
                'request_id' => $requestId,
                'results' => $results,
                'message' => 'Video processing request submitted successfully. Processing will continue asynchronously.',
                'message_id' => $this->entity->getId(),
                'status' => 'IN_QUEUE',
                'api_url' => $apiUrl,
                'video_type' => $isImageToVideo ? 'image-to-video' : 'text-to-video',
                'is_google_service' => $isGoogleService,
                'videoKey' => $videoKey,
            ];
        } catch (Exception $e) {
            return [
                'result' => false,
                'message_id' => $this->entity->getId(),
                'message' => 'Error submitting video processing request: ' . $e->getMessage(),
            ];
        }
    }

    protected function getFilesWithRetry(Model $entity, int $maxAttempts = 5, int $delaySeconds = 2): Collection
    {
        $attempts = 0;

        while ($attempts < $maxAttempts) {
            $entity->refresh();
            $files = $entity->getFiles();

            if ($files->isNotEmpty()) {
                return $files;
            }

            $attempts++;
            if ($attempts < $maxAttempts) {
                sleep($delaySeconds);
            }
        }

        return new Collection();
    }

    /**
     * Submit text-to-video request and return request ID
     */
    protected function submitTextToVideo(string $videoModel, string $apiUrl): array
    {
        // Get default values from app settings
        $defaultValues = $this->getDefaultVideoValues('text-to-video');

        // Submit the video generation request
        $submitPayload = [
            'operation' => 'submit',
            'model' => $videoModel,
            'prompt' => $this->entity->message['prompt'] ?? '',
            'resolution' => $defaultValues['resolution'] ?? '720p',
        ];

        // Add optional webhook URL if configured
        $webhookUrl = $this->entity->app->get('PROMPT_VIDEO_WEBHOOK_URL');
        if ($webhookUrl) {
            $submitPayload['webhookUrl'] = $webhookUrl;
        }

        // Add other optional parameters based on model configuration
        if (isset($defaultValues['aspect_ratio'])) {
            $submitPayload['aspect_ratio'] = $defaultValues['aspect_ratio'];
        }
        if (isset($defaultValues['generate_audio'])) {
            $submitPayload['generate_audio'] = $defaultValues['generate_audio'];
        }
        if (isset($defaultValues['duration'])) {
            $submitPayload['duration'] = $defaultValues['duration'];
        }
        if (isset($defaultValues['negative_prompt'])) {
            $submitPayload['negative_prompt'] = $defaultValues['negative_prompt'];
        }
        if (isset($defaultValues['style'])) {
            $submitPayload['style'] = $defaultValues['style'];
        }
        if (isset($defaultValues['prompt_optimizer'])) {
            $submitPayload['prompt_optimizer'] = $defaultValues['prompt_optimizer'];
        }

        $submitResponse = $this->submitVideoRequest($submitPayload, $apiUrl);

        if (! isset($submitResponse['request_id'])) {
            throw new Exception('Failed to submit video for processing: ' . json_encode($submitResponse));
        }

        return [
            'request_id' => $submitResponse['request_id'],
            'payload' => $submitPayload,
            'model' => $videoModel,
        ];
    }

    /**
     * Submit image-to-video request and return request ID
     */
    protected function submitImageToVideo(array $imageUrlsArray, string $videoModel, string $apiUrl): array
    {
        // Get default values from app settings
        $defaultValues = $this->getDefaultVideoValues('image-to-video');

        // Submit the video generation request
        $submitPayload = [
            'operation' => 'submit',
            'model' => $videoModel,
            'image_url' => $imageUrlsArray,
            'prompt' => $this->entity->message['prompt'] ?? '',
        ];

        // Add optional webhook URL if configured
        $webhookUrl = $this->entity->app->get('PROMPT_VIDEO_WEBHOOK_URL');
        if ($webhookUrl) {
            $submitPayload['webhookUrl'] = $webhookUrl;
        }

        // Add other optional parameters based on model configuration
        if (isset($defaultValues['prompt_optimizer'])) {
            $submitPayload['prompt_optimizer'] = $defaultValues['prompt_optimizer'];
        }
        if (isset($defaultValues['aspect_ratio'])) {
            $submitPayload['aspect_ratio'] = $defaultValues['aspect_ratio'];
        }
        if (isset($defaultValues['resolution'])) {
            $submitPayload['resolution'] = $defaultValues['resolution'];
        }
        if (isset($defaultValues['duration'])) {
            $submitPayload['duration'] = $defaultValues['duration'];
        }
        if (isset($defaultValues['negative_prompt'])) {
            $submitPayload['negative_prompt'] = $defaultValues['negative_prompt'];
        }

        $submitResponse = $this->submitVideoRequest($submitPayload, $apiUrl, true);

        if (! isset($submitResponse['request_id'])) {
            throw new Exception('Failed to submit image-to-video for processing: ' . json_encode($submitResponse));
        }

        return $submitResponse;
    }

    /**
     * Submit a video generation request
     */
    protected function submitVideoRequest(array $payload, string $apiUrl, bool $isVideo = false): array
    {
        if ($this->isGoogleService && $isVideo) {
            // For Google services, use multipart form data
            $httpRequest = Http::asMultipart();

            // Add each field from payload as form data
            foreach ($payload as $key => $value) {
                if ($key === 'image_url') {
                    // Download the image content and send as file
                    $imageContent = Http::get(is_array($value) ? $value[0] : $value)->body();
                    $httpRequest = $httpRequest->attach(
                        'image',
                        $imageContent,
                        'image.png' // Default filename, could be extracted from URL if needed
                    );
                } else {
                    $httpRequest = $httpRequest->attach((string) $key, (string) $value);
                }
            }

            $response = $httpRequest->post($apiUrl);
        } else {
            // For non-Google services, use JSON
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post($apiUrl, $payload);
        }

        return $response->json();
    }

    /**
     * Get default video values from app settings based on the model from the message
     */
    protected function getDefaultVideoValues(string $type): array
    {
        $settings = $this->entity->app->get('llm_list_video_categorization_dev');

        if (! $settings || ! is_array($settings)) {
            return [];
        }

        // Get the model value from the message
        $messageModel = $this->entity->message['ai_model']['value'] ?? null;

        if (! $messageModel) {
            return [];
        }

        $videoKey = $type === 'text-to-video' ? 'fal-ai/text-to-video' : 'fal-ai/image-to-video';

        // Search through all categories for the video key
        foreach ($settings as $category) {
            if (! isset($category['value']) || ! is_array($category['value'])) {
                continue;
            }

            foreach ($category['value'] as $videoTypeConfig) {
                if (isset($videoTypeConfig['key']) && $videoTypeConfig['key'] === $videoKey) {
                    if (isset($videoTypeConfig['value']) && is_array($videoTypeConfig['value'])) {
                        // Find the model configuration that matches the message model
                        foreach ($videoTypeConfig['value'] as $modelConfig) {
                            if (isset($modelConfig['model']) &&
                                (str_contains($modelConfig['model'], $messageModel) || str_contains($messageModel, $modelConfig['model']))) {
                                if (isset($modelConfig['input_config'])) {
                                    return $this->extractDefaultsFromInputConfig($modelConfig['input_config']);
                                }
                            }
                        }
                    }
                }
            }
        }

        return [];
    }

    /**
     * Extract default values from input_config (taking position 0 from array values)
     */
    private function extractDefaultsFromInputConfig(array $inputConfig): array
    {
        $defaults = [];

        foreach ($inputConfig as $key => $values) {
            // Skip comment fields
            if (strpos($key, '__') === 0 && strpos($key, '_comment__') !== false) {
                continue;
            }

            if (is_array($values) && ! empty($values)) {
                // Take the first value (position 0) from the array
                $defaults[$key] = $values[0];
            } elseif (! is_array($values)) {
                $defaults[$key] = $values;
            }
        }

        return $defaults;
    }
}
