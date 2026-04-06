<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\DataTransferObject;

use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\ContactType;
use Spatie\LaravelData\Data;

class Contact extends Data
{
    public readonly int $contacts_types_id;

    public function __construct(
        public readonly string $value,
        ?int $contacts_types_id = null,
        public readonly int $weight = 0,
        public readonly string|int|null $id = null,
        public readonly int|bool|null $is_opt_out = null
    ) {
        $this->contacts_types_id = $contacts_types_id ?? $this->inferContactType($value);
    }

    protected function inferContactType(string $value): int
    {
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return ContactTypeEnum::EMAIL->value;
        }

        return ContactTypeEnum::PHONE->value;
    }

    public function getType(): string
    {
        return strtolower(ContactType::getById($this->contacts_types_id)->name);
    }
}
