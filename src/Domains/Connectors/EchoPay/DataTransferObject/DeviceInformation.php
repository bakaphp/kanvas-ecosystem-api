<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\DataTransferObject;

use Spatie\LaravelData\Data;

class DeviceInformation extends Data
{
    public function __construct(
        public readonly ?string $httpAcceptContent = null,
        public readonly ?string $httpBrowserLanguage = null,
        public readonly ?string $userAgentBrowserValue = null,
        public readonly ?string $ipAddress = null,
        public readonly ?string $fingerprintSessionId = null,
    ) {
    }
}
