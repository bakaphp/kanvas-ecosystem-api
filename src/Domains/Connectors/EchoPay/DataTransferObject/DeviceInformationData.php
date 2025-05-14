<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\DataTransferObject;

use Spatie\LaravelData\Data;

class DeviceInformationData extends Data
{
    public function __construct(
        public readonly string $httpAcceptContent,
        public readonly string $httpBrowserLanguage,
        public readonly string $userAgentBrowserValue,
    ) {
    }
}
