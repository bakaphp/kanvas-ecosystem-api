<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\DataTransferObject;

class ThreeDSResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly string $status,   // e.g. waiting_device_data, pending_authorization, authorized, failed
        public readonly array $data = [], // challenge URL, accessToken, referenceId, etc.
        public readonly array $raw = [],
    ) {
    }
}
