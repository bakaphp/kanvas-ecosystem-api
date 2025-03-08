<?php

declare(strict_types=1);

namespace Kanvas\CustomFields\Modules\DataTransferObject;

use Spatie\LaravelData\Data;
use Baka\Contracts\AppInterface;
use Kanvas\SystemModules\Models\SystemModules;

class CustomFieldModule extends Data
{
    public function __construct(
        public AppInterface $app,
        public string $name,
        public string $model_name,
        public ?SystemModules $systemModules = null
    ) {
    }
}
