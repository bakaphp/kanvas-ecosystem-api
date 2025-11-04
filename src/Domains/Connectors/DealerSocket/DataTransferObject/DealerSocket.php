<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Regions\Models\Regions;

class DealerSocket
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public CompanyInterface $company,
        public AppInterface $app,
        public Regions $region,
        public string $publicKey,
        public string $privateKey,
        public string $username,
        public string $password,
        public string $dealerId,
        public string $vendorName,
    ) {
    }

    /**
     * fromArray.
     */
    public static function viaRequest(array $data, AppInterface $app, CompanyInterface $company): self
    {
        return new self(
            $company,
            $app,
            Regions::getById($data['region_id']),
            $data['publicKey'],
            $data['privateKey'],
            $data['username'],
            $data['password'],
            $data['dealerId'],
            $data['vendorName'],
        );
    }
}
