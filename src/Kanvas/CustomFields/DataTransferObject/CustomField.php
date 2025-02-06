<?php

declare(strict_types=1);

namespace Kanvas\CustomFields\DataTransferObject;

use Spatie\LaravelData\Data;
use Kanvas\CustomFields\Models\CustomFieldsModules;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\CustomFields\Models\CustomFieldsTypes;
use Kanvas\Users\Models\Users;

class CustomField extends Data
{
    public function __construct(
        public Apps $app,
        public Companies $companies,
        public Users $user,
        public CustomFieldsModules $customFieldsModules,
        public CustomFieldsTypes $customFieldsTypes,
        public string $name,
        public ?string $label = null,
        public ?string $attributes = null,
    ) {
    }
}
