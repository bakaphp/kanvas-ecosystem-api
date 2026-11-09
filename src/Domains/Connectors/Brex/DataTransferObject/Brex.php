<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Brex\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;

/**
 * Setup credentials, as submitted through the generic `integrationCompany` mutation.
 */
class Brex
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly string $apiToken,
    ) {
    }

    public static function viaRequest(array $data, AppInterface $app, CompanyInterface $company): self
    {
        return new self(
            app: $app,
            company: $company,
            apiToken: trim((string) ($data['api_token'] ?? '')),
        );
    }
}
