<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Exception;
use finfo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Filesystem\Services\ImageOptimizerService;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;

/**
 * @todo move to prompt mine namespace
 * this as turn into a fix all image related issue , we need to
 * regroup to fix the root cause
 */
class OptimizeImageFromMessageActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Model $message, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        try {
            $company = $app->getAppCompany();
        } catch (ModelNotFoundException $e) {
            $company = $message->company;
        }

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($message, $app, $integrationCompany, $additionalParams) {
                //$messageContent = ! is_array($message->message) ? json_decode($message->message, true) : $message->message;
                $messageContent = $message->message;

                $updatedChildrenFixImage = false;
                $generateTitle = false;
                $error = null;
                //fix prompts issue with images on mobile that it requires image on the children not nugget
                if ($message->messageType->verb === 'memo'
                    && ! isset($messageContent['image'])
                    && isset($messageContent['nugget'])) {
                    $updatedChildrenFixImage = true;

                    $messageContent['image'] = $messageContent['nugget'];
                    $message->message = $messageContent;
                    $message->saveOrFail();
                }

                //fix issue with prompt and not title
                if ($message->messageType->verb === 'prompt' && ! isset($messageContent['title']) && isset($messageContent['prompt'])) {
                    try {
                        $generateTitle = true;
                        $messageContent['title'] = $this->generateTitleByPrompt($messageContent['prompt']);
                        $message->message = $messageContent;
                        $message->saveOrFail();
                    } catch (Exception $e) {
                        $error = $e->getMessage();
                        //log error but continue
                    }
                }

                if (! isset($messageContent['image']) && ! isset($messageContent['ai_image'])) {
                    return [
                        'result' => false,
                        'message' => 'Message does not have an image url',
                        'updatedChildrenFixImage' => $updatedChildrenFixImage,
                        'generatedTitle' => $generateTitle,
                        'error' => $error,
                    ];
                }

                // Safely retrieve the image URL based on message type
                if ($message->parent_id) {
                    // For child messages, use 'image' key
                    if (! isset($messageContent['image'])) {
                        return $this->failWorkflow([
                            'result' => false,
                            'message' => 'Child message does not have an image url',
                            'updatedChildrenFixImage' => $updatedChildrenFixImage,
                            'generatedTitle' => $generateTitle,
                            'error' => $error,
                        ]);
                    }
                    $imageUrl = $messageContent['image'];
                } else {
                    // For parent messages, use 'ai_image.image' key
                    if (! isset($messageContent['ai_image']) || ! isset($messageContent['ai_image']['image'])) {
                        return $this->failWorkflow([
                            'result' => false,
                            'message' => 'Parent message does not have a valid AI image url',
                            'content' => $messageContent,
                            'updatedChildrenFixImage' => $updatedChildrenFixImage,
                            'generatedTitle' => $generateTitle,
                            'error' => $error,
                        ]);
                    }
                    $imageUrl = $messageContent['ai_image']['image'];
                }

                if (! Str::isUrl($imageUrl)) {
                    return $this->failWorkflow([
                        'result' => false,
                        'message' => 'The provided image URL is not valid',
                        'updatedChildrenFixImage' => $updatedChildrenFixImage,
                        'generatedTitle' => $generateTitle,
                        'error' => $error,
                    ]);
                }

                $tempFilePath = ImageOptimizerService::optimizeImageFromUrl($imageUrl);
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

                $filesystem = new FilesystemServices($app);
                $fileSystemRecord = $filesystem->upload($uploadedFile, $message->user);
                $defaultCompany = null;
                $defaultUser = null;

                $defaultCompanyBranchId = $app->get(AppSettingsEnums::GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY->getValue());
                if ($defaultCompanyBranchId) {
                    //to avoid duplicate tags for each message company
                    $defaultCompany = CompaniesBranches::getById($defaultCompanyBranchId)->company;
                    $defaultUser = $defaultCompany->user;
                }

                if (! $message->parent_id && isset($messageContent['ai_image'])) {
                    $tempMessageArray = $messageContent;
                    $tempMessageArray['ai_image'] = array_merge($messageContent['ai_image'], ['image' => $fileSystemRecord->url]);
                    $message->message = $tempMessageArray;
                    $message->addTag('image', $app, $defaultUser, $defaultCompany);
                    $message->saveOrFail();
                    $imageTitle = $messageContent['title'] ?? '';
                    // Update child messages too

                    foreach ($message->children as $childMessage) {
                        //$childMessageArray = is_array($childMessage->message) ? $childMessage->message : json_decode($childMessage->message, true);
                        $childMessageArray = $childMessage->message;
                        if (! is_array($childMessageArray) || ! array_key_exists('image', $childMessageArray)) {
                            continue;
                        }
                        $tempChildMessageArray = $childMessageArray;
                        $tempChildMessageArray['image'] = $fileSystemRecord->url;
                        if (! empty($imageTitle)) {
                            $tempChildMessageArray['title'] = $imageTitle;
                        }

                        //$childMessage->message = json_encode($tempChildMessageArray);
                        $childMessage->message = $tempChildMessageArray;
                        $childMessage->addTag('image', $app, $defaultUser, $defaultCompany);
                        $childMessage->saveOrFail();
                    }
                } elseif ($message->parent_id && isset($messageContent['image'])) {
                    $tempMessageArray = $messageContent;
                    $tempMessageArray['image'] = $fileSystemRecord->url;
                    //$message->message = json_encode($tempMessageArray);
                    $message->message = $tempMessageArray;
                    $message->addTag('image', $app, $defaultUser, $defaultCompany);
                    $message->saveOrFail();
                } else {
                    $message->addTag('text', $app, $defaultUser, $defaultCompany);
                }

                // Clean up the temporary file
                unlink($tempFilePath);

                return [
                    'result' => true,
                    'message' => 'Image optimized and uploaded',
                    'data' => $fileSystemRecord,
                    'message_id' => $message->getId(),
                    'updatedChildrenFixImage' => $updatedChildrenFixImage,
                    'generatedTitle' => $generateTitle,
                    'error' => $error,
                ];
            },
            company: $company,
        );
    }

    private function generateTitleByPrompt(string $prompt): string
    {
        $response = Prism::text()
            ->using(Provider::Gemini, 'gemini-2.0-flash')
            ->withPrompt('Generate a short concise title from this prompt: ' . $prompt . '.Choose just one title, dont give me suggestions')
            ->asText();

        return str_replace(['```', 'json'], '', $response->text);
    }
}
