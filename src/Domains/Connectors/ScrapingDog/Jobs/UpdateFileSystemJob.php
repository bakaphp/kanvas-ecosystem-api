<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapingDog\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Kanvas\Inventory\Variants\Models\Variants;

class UpdateFileSystemJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Variants $variant,
        protected array $files,
    ) {
    }

    public function handle(): void
    {
        foreach ($this->files as $file) {
            $this->variant->addFileFromUrl($file['url'], $file['name']);
        }
        $this->variant->refresh()->load(['product', 'attributes', 'files', 'customFields']);
    }
}
