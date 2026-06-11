<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\DataTransferObject;

use Kanvas\Apps\Models\Apps;
use Spatie\LaravelData\Data;

class AgentType extends Data
{
    public function __construct(
        public Apps $app,
        public string $name,
        public bool $is_active = true,
        public bool $is_published = false,
        public bool $is_multi_agent = false,
        public ?string $description = null,
        public ?string $provider = null,
        public ?string $handler = null,
        public ?string $config = null,
        public ?string $role = null,
        public ?string $soul = null,
        public ?string $instructions = null,
        public ?string $output_format = null,
        public mixed $multi_agent_list = null,
        public bool $is_default = false,
        public int $weight = 0,
    ) {
    }
}
