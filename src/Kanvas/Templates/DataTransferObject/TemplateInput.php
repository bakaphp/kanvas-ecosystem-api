<?php

declare(strict_types=1);

namespace Kanvas\Templates\DataTransferObject;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

/**
 * AppsData class.
 */
class TemplateInput extends Data
{
    /**
     * Constructs function.
     *
     * @param Apps $app
     * @param string $name
     * @param string $template
     * @param Companies|null $company
     * @param Users|null $user
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
