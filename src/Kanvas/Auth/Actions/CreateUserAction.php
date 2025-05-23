<?php

declare(strict_types=1);

namespace Kanvas\Auth\Actions;

use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Kanvas\AccessControlList\Actions\AssignRoleAction;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Auth\Exceptions\AuthenticationException;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\DataTransferObject\Company;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Enums\StateEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Services\SetupService;
use Kanvas\Users\Actions\AssignCompanyAction;
use Kanvas\Users\Enums\StatusEnums;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Users\Services\UserNotificationService;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Throwable;

class CreateUserAction
{
    protected Apps $app;
    protected bool $runWorkflow = true;
    protected bool $extraValidation = false;

    public function __construct(
        protected RegisterInput $data,
        ?Apps $app = null
    ) {
        $this->app = $app ?? app(Apps::class);
    }

    public function execute(): Users
    {
        $newUser = false;
        $company = $this->data->branch ? $this->data->branch->company : null;

        $this->validateEmail();

        try {
            /**
             * If the user exist we have to verify if it exist in this app
             * if it does , means the user already exist
             * if it doesn't than we have to create the user in this app and with a new company
             */
            $user = Users::getByEmail($this->data->email);

            try {
                UsersRepository::belongsToThisApp($user, $this->app);

                throw new AuthenticationException('Email has already been taken.');
            } catch (ModelNotFoundException $e) {
                $this->registerUserInApp($user);
            }
        } catch (ModelNotFoundException $e) {
            $newUser = true;
            $user = $this->createNewUser();

            $this->registerUserInApp($user);
            $this->assignUserRole($user);
        }

        if ($company === null) {
            $company = $this->createCompany($user);
        }

        $this->assignCompany($user);

        if ($newUser && $company !== null) {
            (new SetupService())->onBoarding(
                $user,
                $this->app,
                $company
            );
        }

        UserNotificationService::sendWelcomeEmail($this->app, $user, $company);

        if ($this->runWorkflow) {
            $user->fireWorkflow(
                WorkflowEnum::REGISTERED->value,
                true,
                [
                    'company' => $company,
                    'password' => $this->data->raw_password,
                    'app' => $this->app,
                ]
            );
        }

        return $user;
    }

    protected function validateEmail(): void
    {
        $validator = Validator::make(
            ['email' => $this->data->email],
            ['email' => 'required|email']
        );

        // This is the second time that we need get user data without an exception.
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    protected function validateNames(): void
    {
        $validator = Validator::make(
            [
                'firstname' => $this->data->firstname,
                'lastname' => $this->data->lastname,
            ],
            [
                'firstname' => 'required|different:lastname',
                'lastname' => 'required|different:firstname',
            ],
            [
                'firstname.different' => 'Registration information appears to be invalid.',
                'lastname.different' => 'Registration information appears to be invalid.',
            ]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    protected function validatePhoneNumber(): void
    {
        $totalDigits = $this->app->get('register_user_phone_number_digits') ?? 10;

        $validator = Validator::make(
            [
                'phone_number' => $this->data->phone_number,
            ],
            [
                'phone_number' => ['nullable', 'numeric', 'digits_between:1,' . $totalDigits],
            ],
            [
                'phone_number.digits_between' => 'Invalid phone number.',
            ]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    protected function createNewUser(): Users
    {
        $user = new Users();
        $user->firstname = $this->data->firstname;
        $user->lastname = $this->data->lastname;
        $user->displayname = $this->data->displayname;
        $user->email = $this->data->email;
        $user->password = $this->data->password;
        $user->phone_number = $this->data->phone_number;
        $user->cell_phone_number = $this->data->cell_phone_number;
        $user->sex = AppEnums::DEFAULT_SEX->getValue();
        $user->dob = date('Y-m-d');
        $user->lastvisit = date('Y-m-d H:i:s');
        $user->registered = date('Y-m-d H:i:s');
        $user->timezone = AppEnums::DEFAULT_TIMEZONE->getValue();
        $user->user_active = StatusEnums::ACTIVE->getValue();
        $user->status = StatusEnums::ACTIVE->getValue();
        $user->banned = StateEnums::NO->getValue();
        $user->user_login_tries = 0;
        $user->user_last_login_try = 0;
        $user->default_company = $this->data->default_company ?? StateEnums::NO->getValue();
        $user->default_company_branch = StateEnums::NO->getValue();
        $user->session_time = time();
        $user->session_page = StateEnums::NO->getValue();
        $user->language = $user->language ?: AppEnums::DEFAULT_LANGUAGE->getValue();
        $user->user_activation_key = Hash::make(time());
        $user->roles_id = AppEnums::DEFAULT_ROLE_ID->getValue(); //@todo : remove this , legacy code
        $user->system_modules_id = 2;

        //create a new user assign it to the app and create the default company
        $user->saveOrFail();

        $user->setAll($this->data->custom_fields);

        return $user;
    }

    protected function registerUserInApp(Users $user): void
    {
        $userRegisterInApp = new RegisterUsersAppAction($user, $this->app);
        $userRegisterInApp->execute($this->data->password);
    }

    protected function assignUserRole(Users $user): void
    {
        $roles = $this->data->role_ids;
        if (empty($roles)) {
            $defaultAppSettingsRole = $this->app->get(AppSettingsEnums::DEFAULT_SIGNUP_ROLE->getValue());
            $roles = [RolesEnums::getRoleBySlug($defaultAppSettingsRole ?? RolesEnums::ADMIN->value)];
        }

        foreach ($roles as $role) {
            $userRole = RolesRepository::getByMixedParamFromCompany(
                param: $role,
                app: $this->app
            );

            $assignRole = new AssignRoleAction(
                $user,
                $userRole
            );
            $assignRole->execute();
        }
    }

    protected function assignCompany(Users $user): void
    {
        if ($this->data->branch === null) {
            return ;
        }
        $defaultRole = RolesEnums::USER->value;

        try {
            $selectedRoleId = ! empty($this->data->role_ids) ? $this->data->role_ids[0] : $defaultRole;

            $role = RolesRepository::getByMixedParamFromCompany(
                param: $selectedRoleId,
                app: $this->app
            );
        } catch (Throwable $e) {
            $role = RolesRepository::getByMixedParamFromCompany(
                param: $defaultRole,
                app: $this->app
            );
        }

        (new AssignCompanyAction(
            $user,
            $this->data->branch,
            $role,
            $this->app
        ))->execute();
    }

    protected function createCompany(Users $user): CompanyInterface
    {
        $createCompany = new CreateCompaniesAction(
            new Company(
                user: $user,
                name: $user->defaultCompanyName ?? $user->displayname . 'CP',
                email: $user->email
            )
        );

        $company = $createCompany->execute();
        $branch = $company->branch()->firstOrFail();

        $user->default_company = (int) $company->id;
        $user->default_company_branch = (int) $branch->id;
        $user->saveOrFail();

        $action = new AssignCompanyAction($user, $branch);
        $action->execute();

        return $company;
    }

    public function disableWorkflow(): void
    {
        $this->runWorkflow = false;
    }

    public function enableExtraValidation(): void
    {
        $this->extraValidation = true;
    }
}
