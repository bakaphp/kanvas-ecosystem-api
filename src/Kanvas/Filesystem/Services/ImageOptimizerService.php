<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Services;

use Kanvas\Filesystem\Models\Filesystem;
use Spatie\ImageOptimizer\OptimizerChainFactory;

class ImageOptimizerService
{
    public static function optimizeImageFromFileSystem(Filesystem $file): void
    {
        //Get url from filesystem
        $pathToImage = $file->url;

        //Download the file from url

        //Optimize the file
        $optimizerChain = OptimizerChainFactory::create();
        $optimizerChain->optimize($pathToImage);

        //Upload the file back to the filesystem, replace the url or create another filesystem entry
    }

    public static function optimizeImageFromUrl(string $imageUrl): string
    {
        //Download the file from url
        $imagePath = FilesystemServices::downloadImageFromUrl($imageUrl);

        $optimizerChain = OptimizerChainFactory::create();
        $optimizerChain->optimize($imagePath);

        return $imagePath;
    }

    public static function optimizeImage(string $pathToImage): void
    {
        $optimizerChain = OptimizerChainFactory::create();
        $optimizerChain->optimize($pathToImage);
    }
}