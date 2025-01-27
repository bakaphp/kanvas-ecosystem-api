<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Workflow\KanvasActivity;
use Kanvas\Filesystem\Services\ImageOptimizerService;
use Illuminate\Http\UploadedFile;
use Kanvas\Filesystem\Services\FilesystemServices;

class OptimizeImageFromMessageActivity extends KanvasActivity
{
    public $tries = 3;
    public $queue = 'default';

    public function execute(Model $message, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        $tempFilePath = ImageOptimizerService::optimizeImageFromUrl($params['image_url']);
        $fileName = "testing_optimizer";

        $uploadedFile = new UploadedFile(
            $tempFilePath,
            $fileName,
            'application/pdf',
            null,
            true
        );

        $filesystem = new FilesystemServices($app);
        $fileSystemPath = $filesystem->upload($uploadedFile, $message->user);

        // Clean up the temporary file
        unlink($tempFilePath);

        return [
            'result' => true,
            'message' => 'Image optimized and uploaded',
            'data' => $fileSystemPath,
            'message_id' => $message->getId(),
        ];
    }
}
