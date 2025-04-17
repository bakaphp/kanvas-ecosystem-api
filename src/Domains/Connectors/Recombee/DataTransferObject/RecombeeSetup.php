<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Regions\Models\Regions;

class RecombeeSetup
{
    public function __construct(
        public CompanyInterface $company,
        public AppInterface $app,
        public Regions $region,
        public string $recombeeDatabase,
        public string $privateToken,
        public string $recombeeRegion
    ) {
    }

    public static function fromArray(array $data, AppInterface $app, CompanyInterface $company): self
    {
        return new self(
            $company,
            $app,
            Regions::getById($data['region_id'], $app),
            $data['database_id'],
            $data['private_token'],
            $data['recombee_region']
        );
    }
}
