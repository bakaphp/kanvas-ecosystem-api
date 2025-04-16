<?php

declare(strict_types=1);

namespace Kanvas\Companies\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Services\AuthenticationService;
use Kanvas\Companies\DataTransferObject\Company;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class UpdateCompaniesAction
{
    public function __construct(
        protected Companies $companies,
        protected Users $user,
        protected Company $data
    ) {
    }

    /**
     * Invoke function.
     */
    public function execute(): Companies
    {
        CompaniesRepository::userAssociatedToCompany($this->companies, $this->user);

        $data = array_filter($this->data->toArray(), function ($value) {
            return $value !== null;
        });

        if (isset($data['is_active'])) {
            $users = $this->companies->users;
            $app = app(Apps::class);
            if ($data['is_active'] === false) {
                foreach ($users as $user) {
                    $this->deactivateUser($user, $app);
                }
            } else {
                foreach ($users as $user) {
                    $this->activateUser($user, $app);
                }
            }
        }

        $this->companies->updateOrFail($data);

        if ($this->data->files) {
            $this->companies->addMultipleFilesFromUrl($this->data->files);
        }

        if ($this->data->custom_fields) {
            $this->companies->setAll($this->data->custom_fields);
        }

        return $this->companies;
    }

    public function deactivateUser(Users $user, Apps $app): bool
    {
        $userAssociate = UsersRepository::belongsToThisApp($user, $app);
        AuthenticationService::logoutFromAllDevices($userAssociate->user, $app);
        return $userAssociate->deActive();
    }

    public function activateUser(Users $user, Apps $app): bool
    {
        $userAssociate = UsersRepository::belongsToThisApp($user, $app);
        return $userAssociate->active();
    }
}
