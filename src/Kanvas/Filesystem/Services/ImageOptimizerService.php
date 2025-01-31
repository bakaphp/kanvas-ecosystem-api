<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Services;

use Kanvas\Filesystem\Models\Filesystem;
use Spatie\ImageOptimizer\OptimizerChainFactory;

class ImageOptimizerService
{
    /**
     * Optimize an image from a URL.
     *
     * @param string $imageUrl
     *
     * @return string
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
