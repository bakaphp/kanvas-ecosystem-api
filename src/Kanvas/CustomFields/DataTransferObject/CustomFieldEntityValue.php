<?php

declare(strict_types=1);

namespace Kanvas\CustomFields\DataTransferObject;

use Spatie\LaravelData\Data;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;
use Kanvas\Companies\Models\Companies;
use Kanvas\CustomFields\Models\CustomFieldsModules;
use Kanvas\CustomFields\Models\CustomFields;

class CustomFieldEntityValue extends Data
{
    public function __construct(
        public Apps $app,
        public Companies $companies,
        public Users $users,
        public CustomFields $customFields,
        public int $entity_id,
        public int $value
    ) {
    }
}
