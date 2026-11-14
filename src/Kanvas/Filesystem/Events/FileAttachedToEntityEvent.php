<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kanvas\Filesystem\Models\Filesystem;

/**
 * A file was attached to a record. Like the record's workflow events, it is not raised for a
 * record whose follow-on work was switched off for the write (an import, a bulk copy).
 */
final class FileAttachedToEntityEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Model $entity,
        public readonly Filesystem $filesystem
    ) {
    }
}
