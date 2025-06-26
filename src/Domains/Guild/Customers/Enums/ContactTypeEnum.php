<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Enums;

enum ContactTypeEnum: int
{
    case EMAIL = 1; // Personal Email
    case PHONE = 2; // Home Phone
    case CELLPHONE = 3;
    case WORK_PHONE = 8;
    case PRIMARY_EMAIL = 9;
    case SECONDARY_EMAIL = 10;

    public function getName(): string
    {
        return match ($this->value) {
            self::EMAIL->value => 'Email',
            self::PHONE->value => 'Phone',
            self::CELLPHONE->value => 'Cellphone',
            self::WORK_PHONE->value => 'Work Phone',
            self::PRIMARY_EMAIL->value => 'Primary Email',
            self::SECONDARY_EMAIL->value => 'Secondary Email',
        };
    }
}
