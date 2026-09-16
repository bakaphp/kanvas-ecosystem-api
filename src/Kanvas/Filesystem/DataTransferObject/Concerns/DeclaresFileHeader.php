<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\DataTransferObject\Concerns;

use Kanvas\Exceptions\ValidationException;

trait DeclaresFileHeader
{
    /**
     * A mapper fed by a connector record has no header row, so `file_header` is optional — but a
     * mapper that says it reads a file with one is unusable without it, and the failure would
     * otherwise surface much later as a mis-keyed import.
     */
    public function assertHeaderIsUsable(): void
    {
        if ($this->has_header && empty($this->header)) {
            throw new ValidationException('file_header is required when has_header is true');
        }
    }
}
