<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Spatie\LaravelData\Data;

class NetSuite extends Data
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public CompanyInterface $company,
        public AppInterface $app,
        public string $account,
        public string $consumerKey,
        public string $consumerSecret,
        public string $token,
        public string $tokenSecret,
    ) {
    }

    /**
     * fromArray.
     */
    public static function fromMultiple(array $data, AppInterface $app, CompanyInterface $company): self
    {
        return new self(
            $company,
            $app,
            $data['account'],
            $data['consumerKey'],
            $data['consumerSecret'],
            $data['token'],
            $data['tokenSecret'],
        );
    }
}
