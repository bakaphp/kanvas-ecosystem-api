<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Actions\DataTransferObject;

use Kanvas\ActionEngine\Actions\Models\Action;
use Spatie\LaravelData\Data;

class CompanyAction extends Data
{
    public function __construct(
        public readonly Action $action,
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly mixed $form_config = null,
        public readonly mixed $config = null,
        public readonly ?bool $is_active = null,
        public readonly ?bool $is_published = null,
        public readonly ?float $weight = null,
        public readonly ?int $parent_id = null,
        public readonly ?string $status = null,
    ) {
    }
}
