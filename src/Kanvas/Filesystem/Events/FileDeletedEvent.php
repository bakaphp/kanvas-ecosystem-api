<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kanvas\Filesystem\Models\Filesystem;

/**
 * A file was taken off the record it was attached to. Raised wherever the file's `deleted`
 * workflow event is.
 */
final class FileDeletedEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Filesystem $filesystem
    ) {
    }
}
