<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VoiceBridge\DataTransferObject;

use Baka\Contracts\AppInterface;

class VoiceBridge
{
    public function __construct(
        public AppInterface $app,
        public string $apiKey,
        public string $baseUrl,
        public string $companyId,
    ) {
    }

    public static function viaRequest(array $data, AppInterface $app): self
    {
        return new self(
            app: $app,
            apiKey: $data['api_key'],
            baseUrl: $data['base_url'],
            companyId: $data['company_id'],
        );
    }
}
