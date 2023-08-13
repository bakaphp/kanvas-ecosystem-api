<?php

declare(strict_types=1);

namespace Kanvas\Auth\Services;

use Illuminate\Support\Arr;
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
            $customFields = null;
            $files = null;
            if (Arr::exists($data, 'custom_fields')) {
                $customFields = $data['custom_fields'];
                unset($data['custom_fields']);
            }

            if (Arr::exists($data, 'files')) {
                $files = $data['files'];
                unset($data['files']);
            }

            $this->user->update(array_filter($data));

            if ($customFields) {
                $this->user->setAll($customFields);
            }

            if ($files) {
                $this->user->addMultipleFilesFromUrl($files);
            }
        } catch (InternalServerErrorException $e) {
            throw new InternalServerErrorException($e->getMessage());
        }

        return $this->user;
    }
}
