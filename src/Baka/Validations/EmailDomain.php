<?php

declare(strict_types=1);

namespace Baka\Validations;

use Illuminate\Validation\ValidationException;

class EmailDomain
{
    /**
     * Verify if email domain is valid.
     *
     * @param string $email
     *
     * @return bool
     */
    public static function verifyDomain(string $email) : bool
    {
        if (!checkdnsrr(array_pop(explode('@', $email . '.')), 'MX')) {
            throw new ValidationException('Email domain is not valid.');
        }

        return true;
    }
}
