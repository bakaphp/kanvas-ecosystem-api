<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Workflow\Activity;

class DownloadImageActivity extends Activity implements WorkflowActivityInterface
{
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $file = file_get_contents($entity->url);
        $filesystemName = uniqid(Str::random(10) . '_');
        Storage::disk('local')->put($filesystemName, $file);
        if ($app->get('size_product_width') && $app->get('size_product_height')) {
            $width = $app->get('size_product_width');
            $height = $app->get('size_product_height');
            $path = storage_path('app/') . $filesystemName;
            $filesystemName = $this->resizeImageGD($path, $width, $height);
        }

        $fileSystemService = new FilesystemServices($app, $entity->company);
        $storage = $fileSystemService->getStorageByDisk();

        $fileDownload = Storage::disk('local')->get($filesystemName);

        $file = $storage->put($filesystemName, $fileDownload, [
            'visibility' => 'public',
        ]);

        Storage::disk('local')->delete($filesystemName);
        $entity->url = $storage->url($filesystemName);
        $entity->saveOrFail();

        return [
            'message' => 'Image downloaded',
            'filesystemName' => $filesystemName,
            'url' => $entity->url,
        ];
    }

    /**
     * Resize an image using GD.
     */
    public static function resizeImageGD(string $file, int $maxWidth, int $maxHeight): string
    {
        list($originalWidth, $originalHeight) = getimagesize($file);

        $aspectRatio = $originalWidth / $originalHeight;

        if ($maxWidth / $maxHeight > $aspectRatio) {
            $newWidth = round($maxHeight * $aspectRatio);
            $newHeight = $maxHeight;
        } else {
            $newWidth = $maxWidth;
            $newHeight = round($maxWidth / $aspectRatio);
        }
        $newHeight = (int)$newHeight;
        $newWidth = (int)$newWidth;

        $newImage = imagecreatetruecolor($newWidth, $newHeight);

        $ext = pathinfo($file, PATHINFO_EXTENSION);

        $name = basename($file, '.' . $ext);

        $path = storage_path('app/') ;

        $newFile = $name . '_' . $maxWidth . 'x' . $maxHeight . '.' . $ext;
        $sourceImage = imagecreatefromjpeg($file);
        imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

        imagejpeg($newImage, $path . $newFile);

        imagedestroy($sourceImage);
        imagedestroy($newImage);

        return $newFile;
    }
}
