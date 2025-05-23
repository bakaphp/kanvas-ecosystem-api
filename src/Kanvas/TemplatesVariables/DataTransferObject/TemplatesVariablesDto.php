<?php

declare(strict_types=1);

namespace Kanvas\TemplatesVariables\DataTransferObject;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

/**
 * AppsData class.
 */
class TemplatesVariablesDto extends Data
{
    /**
     * Constructs function.
     *
     */
    public function __construct(
        public string $name,
        public string $value,
        public int $template_id,
        public Apps $app,
        public ?Companies $company = null,
        public ?Users $user = null,
    ) {
    }
}
