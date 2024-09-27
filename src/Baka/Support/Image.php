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
        // Obtén el tamaño original de la imagen
        list($originalWidth, $originalHeight) = getimagesize($file);

        // Calcula la proporción de la imagen original
        $aspectRatio = $originalWidth / $originalHeight;

        // Ajusta el ancho y alto basado en la proporción
        if ($maxWidth / $maxHeight > $aspectRatio) {
            $newWidth = round($maxHeight * $aspectRatio); // Mantén proporción con base en la altura
            $newHeight = $maxHeight;
        } else {
            $newWidth = $maxWidth;
            $newHeight = round($maxWidth / $aspectRatio); // Mantén proporción con base en el ancho
        }
        $newHeight = (int)$newHeight;
        $newWidth = (int)$newWidth;

        // Crea una nueva imagen de destino con el nuevo tamaño
        $newImage = imagecreatetruecolor($newWidth, $newHeight);

        $ext = pathinfo($file, PATHINFO_EXTENSION);

        $name = basename($file, '.' . $ext);

        $path = storage_path('app/temporal/') ;

        $newFile = $name . '_' . $maxWidth . 'x' . $maxHeight . '.' . $ext;
        // Cargar la imagen original
        $sourceImage = imagecreatefromjpeg($file);
        // Redimensiona la imagen
        imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

        // Guarda la imagen redimensionada
        imagejpeg($newImage, $path . $newFile);

        imagedestroy($sourceImage);
        imagedestroy($newImage);

        return 'temporal/' . $newFile;
    }
}
