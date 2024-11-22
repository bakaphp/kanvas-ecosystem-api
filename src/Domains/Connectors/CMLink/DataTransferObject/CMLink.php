<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CMLink\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Spatie\LaravelData\Data;

class CMLink extends Data
{
    public function __construct(
        public CompanyInterface $company,
        public AppInterface $app,
        public string $app_key,
        public string $app_secret,
        public string $app_account_id,
        public string $app_account_type
    ) {
    }

    public static function fromMultiple(
        array $data,
        AppInterface $app,
        CompanyInterface $company
    ): self {
        return new self(
            $company,
            $app,
            $data['app_key'],
            $data['app_secret'],
            $data['app_account_id'],
            $data['app_account_type']
        );
    }
}
