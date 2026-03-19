<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Spatie\LaravelData\Data;

class AgentMachine extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly string $name,
        public readonly string $host,
        public readonly string $ssh_user,
        public readonly string $ssh_private_key,
        public readonly int $ssh_port = 22,
        public readonly ?string $region = null,
        public readonly int $port_range_start = 20000,
        public readonly int $port_range_end = 30000,
        public readonly int $max_agents = 100,
        public readonly bool $is_active = true,
    ) {
    }
}
