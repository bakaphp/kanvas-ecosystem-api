<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Services;

use Baka\Contracts\AppInterface;
use Exception;
use Kanvas\Connectors\PromptMine\Actions\MessageOrderFulfillmentAction;
use Kanvas\Connectors\PromptMine\Actions\ProcessVideoRequestAction;
use Kanvas\Connectors\PromptMine\Services\VideoProcessingService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\KanvasActivity;

class VideoCreationService extends KanvasActivity
{
    protected ?string $apiUrl = null;
    public $tries = 3;

    public function __construct(
        protected AppInterface $app,
        protected Message $entity,
        protected ?array $params = null,
    ) {
    }

    public function execute(): array
    {
        $this->overwriteAppService($this->app);

        sleep($this->app->get('PROMPT_VIDEO_WAIT_TIME') ?? 10);
        $this->entity->refresh();

        $this->entity->setPrivate();

        try {
            $orderCredit = new MessageOrderFulfillmentAction($this->entity)->execute('video');

            // Use the ProcessVideoRequestAction for the core logic
            $processVideoAction = new ProcessVideoRequestAction($this->entity, $this->app, $this->params);
            $result = $processVideoAction->execute();

            $params['video_url_key'] = isset($result['is_google_service']) && $result['is_google_service'] ? 'videoUri' : 'video_url';
            $params['videoKey'] = $result['videoKey'] ?? null;

            if ($result['result'] && isset($result['request_id'])) {
                // Schedule delayed processing using the service
                $this->scheduleVideoProcessingCheck(
                    $this->entity,
                    $this->app,
                    $result['request_id'],
                    $result['model'],
                    $params
                );
            }

            $result['orderCredit'] = $orderCredit;

            return [
                'result' => true,
                'status' => 'COMPLETED',
                'video_url' => $result['video_url'],
                'result_data' => $result['result_data'],
            ];
        } catch (Exception $e) {
            report($e);

            // return $this->failWorkflow([
            //     'result' => false,
            //     'message_id' => $entity->getId(),
            //     'message' => 'Error submitting video processing request: ' . $e->getMessage(),
            // ]);
        }
    }

    /**
     * Schedule a delayed job to check video processing status
     */
    protected function scheduleVideoProcessingCheck(
        Message $entity,
        AppInterface $app,
        string $requestId,
        string $videoModel,
        array $params
    ): void {
        dispatch(function () use ($entity, $app, $requestId, $videoModel, $params) {
            $service = new VideoProcessingService($entity, $app, $params);
            $service->checkVideoProcessingStatus($requestId, $videoModel, $params);
        })->delay(now()->addMinutes(5)); // Wait 5 minutes before first check
    }
}
