<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\DataTransferObject;

/**
 * A file the vendor holds for an inbound message; fetching it needs the vendor's credentials, so the
 * provider downloads it.
 */
final readonly class InboundAttachment
{
    public function __construct(
        public string $url,
        public string $contentType
    ) {
    }
}
