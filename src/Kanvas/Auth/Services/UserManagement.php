<?php

declare(strict_types=1);

namespace Kanvas\Auth\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Apps\Models\Apps;
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
     *
     * @param array $data
     *
     * @return Users
     */
    public function update(array $data) : Users
    {
        try {
            $this->user->update(array_filter($data));
        } catch (ModelNotFoundException $e) {
            //no email sent
        }

        return $this->user;
    }
}
