<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Actions\DataTransferObject;

use Spatie\LaravelData\Data;

class Action extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly ?string $icon = null,
        public readonly mixed $form_fields = null,
        public readonly mixed $form_config = null,
        public readonly bool $is_active = true,
        public readonly bool $collects_info = false,
        public readonly bool $is_published = true,
        public readonly ?int $pipelines_id = null,
        public readonly ?int $parent_id = null,
    ) {
    }
}
