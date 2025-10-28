<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Kanvas\Filesystem\Services\FilesystemServices;
use Illuminate\Http\Client\Response;
use finfo;
use \Kanvas\Filesystem\Models\Filesystem;

class ConvertTextToSpeechActivity extends KanvasActivity
{
    protected ?string $apiUrl = null;
    protected ?Apps $app = null;
    public $tries = 3;

    public function execute(Message $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        sleep($app->get('PROMPT_VIDEO_WAIT_TIME') ?? 10);
        $entity->refresh();

        $company = $this->getCompany($app, $entity);

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::PROMPT_MINE,
            integrationOperation: function ($entity, $app, $integrationCompany) use ($params) {

                $filesystemRecord = $this->processFalAiTextToSpeech(
                    entity: $entity,
                    params: $params,
                );

                if ($filesystemRecord === null) {
                    return $this->failWorkflow([
                        'result' => false,
                        'message' => 'Failed to retrieve audio file',
                        "message_id" => $entity->getId(),
                    ]);
                }

                return [
                    'result' => true,
                    'message' => 'Text to speech conversion completed successfully',
                    'message_id' => $entity->getId(),
                    'company_id' => $integrationCompany->getId(),
                    'app_id' => $app->getId(),
                ];
                
            },
            company: $company,
        );
    }

    /**
     * Process image with fal.ai
     *
     * @return array [fileSystemRecord, processedImageUrl, requestId]
     */
    protected function processFalAiTextToSpeech(Model $entity, array $params): ?Filesystem
    {
        // Step 1: Submit the image for processing
        $model = 'fal-ai/elevenlabs/tts/eleven-v3';
        $endpoint = $entity->app->get('PROMPT_TEXT_TO_SPEECH_API_URL');
        $response = $this->submitData($endpoint, $entity->message['prompt'], $model, $entity->message['voice'] ?? null)->json();
        $response = $response->json()['audio'];

        $tempFilePath = FilesystemServices::downloadFromUrl($response['url']);

        if ($tempFilePath === null) {
            return null;
        }

        $fileName = basename($tempFilePath);
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tempFilePath);

        $uploadedFile = new UploadedFile(
            $tempFilePath,
            $fileName,
            $mimeType,
            null,
            true
        );

        $filesystem = new FilesystemServices($entity->app);
        $fileSystemRecord = $filesystem->upload($uploadedFile, $entity->user);

        return $fileSystemRecord;
    }

     /**
     * Submit data for processing
     */
    protected function submitData(string $apiUrl, string $prompt, string $model, string $voice): Response
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post($apiUrl, [
            'operation' => 'submit',
            'text' => $prompt,
            'model' => $model,
            'voice' => $voice,
        ]);

        return $response;
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
