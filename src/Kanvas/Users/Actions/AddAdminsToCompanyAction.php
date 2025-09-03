<?php

declare(strict_types=1);

namespace Kanvas\Users\Actions;

use Baka\Contracts\AppInterface;
use Bouncer;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Companies\Enums\B2BSettingsEnums;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Souk\Services\B2BConfigurationService;
use Kanvas\Users\Models\Users;

use function Sentry\captureException;

class AddAdminsToCompanyAction
{
    public function __construct(
        public AppInterface $app,
        public Users $authUser,
        public Companies $company,
        public CompaniesBranches $branch
    ) {
    }

    public function execute(): void
    {
        if ($b2bCompany = B2BConfigurationService::getConfiguredB2BCompany($this->app, $this->company)) {
            try {
                $admins = $this->getAdmins($this->app);
                $addUserCompanyAction = new AddUserCompanyAction(
                    $this->authUser,
                    $this->company,
                    $this->app,
                    $this->branch
                );

                $role = RolesRepository::getByNameFromApp(
                    name: RolesEnums::OWNER->value,
                    app: $this->app
                );

                $addUserCompanyAction->execute($admins, $role->id);
            } catch (Exception $e) {
                captureException($e);
                throw $e;
            }
        }
    }

    public function getAdmins(
        AppInterface $app
    ): Collection {
        $mainAdmins = $app->get(B2BSettingsEnums::B2B_COMPANY_ADMIN_EMAILS->getValue()); // Custom field with comma-separated emails
        $mainAdminEmails = array_map('trim', explode(',', $mainAdmins ?? ''));

        Bouncer::scope()->to(RolesEnums::getScope($app));
        $appAdmins = Users::whereIs(RolesEnums::OWNER->value)->get();

        $allowedAdmins = $appAdmins->filter(function ($user) use ($mainAdminEmails) {
            return in_array($user->email, $mainAdminEmails);
        });

        return $allowedAdmins;
    }
}
