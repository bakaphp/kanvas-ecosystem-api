<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\DataTransferObject;

use Kanvas\Communication\Connections\Models\SharedRegistration;

/**
 * Where the vendor now sends this connection's inbound, confirmed by reading it back.
 */
final readonly class InboundRegistration
{
    /**
     * @param list<OwnedResource> $owned per-connection resources a disconnect removes
     * @param SharedRegistration|null $shared the shared resource this connection now depends on
     */
    public function __construct(
        public array $owned = [],
        public ?SharedRegistration $shared = null
    ) {
    }
}
