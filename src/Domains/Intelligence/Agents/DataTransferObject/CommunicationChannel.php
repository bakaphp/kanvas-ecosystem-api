<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\DataTransferObject;

use Kanvas\Apps\Models\Apps;
use Spatie\LaravelData\Data;

class CommunicationChannel extends Data
{
    public function __construct(
        public Apps $app,
        public string $name,
        public string $description,
        public string $handler,
        public array $config,
        public bool $is_active = true,
        public bool $is_published = false,
    ) {
    }
}
