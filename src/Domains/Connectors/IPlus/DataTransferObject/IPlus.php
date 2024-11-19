<?php

declare(strict_types=1);

namespace Kanvas\Connectors\IPlus\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Spatie\LaravelData\Data;

class IPlus extends Data
{
    public function __construct(
        public CompanyInterface $company,
        public AppInterface $app,
        public string $client_id,
        public string $client_secret
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
            $data['client_id'],
            $data['client_secret']
        );
    }
}
