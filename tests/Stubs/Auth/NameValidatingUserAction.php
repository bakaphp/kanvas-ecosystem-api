<?php

declare(strict_types=1);

namespace Tests\Stubs\Auth;

use Kanvas\Auth\Actions\CreateUserAction;

/**
 * Exposes the protected name validation so it can be exercised without the
 * database writes the full register flow performs.
 */
final class NameValidatingUserAction extends CreateUserAction
{
    public function runNameValidation(): void
    {
        $this->validateNames();
    }
}
