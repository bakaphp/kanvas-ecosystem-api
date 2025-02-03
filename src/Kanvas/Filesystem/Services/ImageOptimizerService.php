<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Services;

use Spatie\ImageOptimizer\OptimizerChainFactory;

class ImageOptimizerService
{
    /**
     * Optimize an image from a URL.
     */
    public static function optimizeImageFromUrl(string $imageUrl): string
    {
        //Download the file from url
        $imagePath = FilesystemServices::downloadImageFromUrl($imageUrl);

        $optimizerChain = OptimizerChainFactory::create();
        $optimizerChain->optimize($imagePath);

        return $imagePath;
    }
}
