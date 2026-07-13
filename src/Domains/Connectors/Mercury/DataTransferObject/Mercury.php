<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;

/**
 * Setup credentials, as submitted through the generic `integrationCompany` mutation.
 */
class Mercury
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly string $apiToken,
        public readonly ?string $baseUrl = null,
    ) {
    }

    public static function viaRequest(array $data, AppInterface $app, CompanyInterface $company): self
    {
        return new self(
            app: $app,
            company: $company,
            apiToken: (string) ($data['api_token'] ?? ''),
            baseUrl: isset($data['base_url']) && $data['base_url'] !== ''
                ? (string) $data['base_url']
                : null,
        );
    }
}
