<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\PromptMine\Actions\ProcessVideoRequestAction;
use Kanvas\Connectors\PromptMine\Services\VideoProcessingService;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class PromptVideoFilterActivity extends KanvasActivity
{
    protected ?string $apiUrl = null;
    protected ?Apps $app = null;
    public $tries = 3;

    public function execute(Message $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        sleep($app->get('PROMPT_VIDEO_WAIT_TIME') ?? 5);
        $this->app = $app;

        $company = $this->getCompany($app, $entity);

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::PROMPT_MINE,
            integrationOperation: function ($entity, $app, $integrationCompany, $additionalParams) use ($params) {
                $entity->setPrivate();

                try {
                    // Use the ProcessVideoRequestAction for the core logic
                    $processVideoAction = new ProcessVideoRequestAction($entity, $app, $params);
                    $result = $processVideoAction->execute();

                    $params['video_url_key'] = isset($result['is_google_service']) && $result['is_google_service'] ? 'videoUri' : 'video_url';
                    $params['videoKey'] = $result['videoKey'] ?? null;

                    if ($result['result'] && isset($result['request_id'])) {
                        // Schedule delayed processing using the service
                        $this->scheduleVideoProcessingCheck(
                            $entity,
                            $app,
                            $result['request_id'],
                            $result['model'],
                            $params
                        );
                    }

                    return $result;
                } catch (Exception $e) {
                    report($e);

                    return [
                        'result' => false,
                        'message_id' => $entity->getId(),
                        'message' => 'Error submitting video processing request: ' . $e->getMessage(),
                    ];
                }
            },
            company: $company,
        );
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
