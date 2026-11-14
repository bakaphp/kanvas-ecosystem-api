<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;

/**
 * A file was taken off the record it was attached to, or deleted. Raised wherever the file's
 * `deleted` workflow event is. It names the file instead of carrying it: by the time a queued
 * listener runs, a deleted file's row can no longer be loaded.
 */
final class FileDeletedEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Apps $app,
        public readonly int $companiesId,
        public readonly string $uuid
    ) {
    }
}
