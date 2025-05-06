<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\DataTransferObject;

use Kanvas\Guild\Customers\Models\ContactType;
use Spatie\LaravelData\Data;

class Contact extends Data
{
    public function __construct(
        public readonly string $value,
        public readonly int $contacts_types_id,
        public readonly int $weight = 0,
        public readonly string|int|null $id = null,
    ) {
    }

    public function getType(): string
    {
        return ContactType::getById($this->contacts_types_id)->name;
    }
}
