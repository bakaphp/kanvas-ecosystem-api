<?php

declare(strict_types=1);

namespace Baka\Support;

use Illuminate\Support\Facades\Storage;

class Image
{
    public static function downloadFileToLocalDisk(string $url, string $path): string
    {
        $file = file_get_contents($url);
        Storage::disk('local')->put($path, $file);

        return $path;
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

        $path = storage_path('app/temporal/') ;

        $newFile = $name . '_' . $maxWidth . 'x' . $maxHeight . '.' . $ext;
        $sourceImage = imagecreatefromjpeg($file);
        imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

        imagejpeg($newImage, $path . $newFile);

        imagedestroy($sourceImage);
        imagedestroy($newImage);

        return 'temporal/' . $newFile;
    }
}
