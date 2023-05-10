<?php

declare(strict_types=1);

namespace Kanvas\Auth\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\InternalServerErrorException;
use Kanvas\Users\Models\Users;

class UserManagement
{
    protected Apps $app;

    /**
     * Construct function.
     */
    public function __construct(
        protected Users $user
    ) {
        $this->app = app(Apps::class);
    }

    /**
     * Update current user data with $data
     */
    public function update(array $data): Users
    {
        try {
            $this->user->update(array_filter($data));
        } catch (InternalServerErrorException $e) {
            throw new InternalServerErrorException($e->getMessage());
        }

        return $this->user;
    }
}
