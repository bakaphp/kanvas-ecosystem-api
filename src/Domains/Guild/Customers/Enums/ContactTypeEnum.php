<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Enums;

enum ContactTypeEnum: int
{
    case EMAIL = 1;
    case PHONE = 2;
    case CELLPHONE = 3;

    public function getName(): string
    {
        return match ($this->value) {
            self::EMAIL => 'Email',
            self::PHONE => 'Phone',
            self::CELLPHONE => 'Cellphone',
        };
    }
}
