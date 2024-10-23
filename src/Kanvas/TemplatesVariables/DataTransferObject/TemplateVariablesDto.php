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
     * @param string $name
     * @param string $value
     * @param Companies|null $company
     * @param Users|null $user
     * @param Apps $app
     */
    public function __construct(
        public Apps $app,
        public string $name,
        public string $template,
        public ?Companies $company = null,
        public ?Users $user = null
    ) {
    }
}
