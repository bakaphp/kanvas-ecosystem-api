<?php

namespace Kanvas\Companies\Companies\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kanvas\Apps\Apps\Models\Apps;
use Kanvas\Companies\Companies\Events\AfterSignupEvent;
use Kanvas\Companies\Companies\Models\Companies;
use Kanvas\Companies\Companies\Repositories\CompaniesRepository;
use Kanvas\Companies\Groups\Models\CompaniesGroups;
use Kanvas\Companies\Groups\Repositories\CompaniesGroupsRepository;
use Kanvas\Roles\Enums\Defaults as RolesDefaults;
use Kanvas\Users\AssociatedApps\Actions\AssociateUsersAppsAction;
use Kanvas\Users\AssociatedCompanies\Actions\AssociateUsersCompaniesAction;
use Log;

class AfterSignupListener implements ShouldQueue
{
    /**
     * Bind Companies Groups and Companies.
     *
     * @param  \App\Events\OrderCreated  $event
     *
     * @return void
     *
     * @todo Cant complete the listener because there are no subscriptions or acl features yet.
     */
    public function handle(AfterSignupEvent $event)
    {
        $company = $event->company;
        $app = app(Apps::class);
        $userData = $event->user;

        //Set Default Company if record is not found
        if (!$company->user->get(Companies::cacheKey())) {
            $company->user->set(Companies::cacheKey(), $company->getKey());
        }

        $assocCompanies = new AssociateUsersCompaniesAction($company->user, $company);
        $assocCompanies->execute();

        $assocApps = new AssociateUsersAppsAction($company->user, $company);
        $assocApps->execute();

        $branch = CompaniesRepository::createBranch($company);

        Log::info('Companies Branch: ', [$branch]);

        // //Set Default Company Branch if record is not found
        if (!$company->user->get($company->branchCacheKey())) {
            $company->user->set($company->branchCacheKey(), $company->branch->getKey());
        }

        //look for the default plan for this app
        CompaniesRepository::registerInApp($company, $app);

        $companiesGroup = CompaniesGroups::where('apps_id', $app->getKey())
                            ->where('users_id', $userData->getKey())
                            ->first();

        if (!$companiesGroup) {
            $companiesGroup = new CompaniesGroups();
            $companiesGroup->name = $company->name;
            $companiesGroup->apps_id = $app->getKey();
            $companiesGroup->users_id = $userData->getKey();
            $companiesGroup->is_default = 1;
            $companiesGroup->save();
        }

        /**
         * Let's associate companies and companies_groups.
         */
        $companiesAssoc = CompaniesGroupsRepository::associate($companiesGroup, $company);

        /**
         * only assign a role to the user within the company if its not a new signup
         * but the creation of a new company to a already user of the app.
         */
        // if (!$company->user->isFirstSignup()) {
        //     $company->user->assignRole(RolesDefaults::DEFAULT->getValue(), $company);
        // }

        //if the app is subscription based, create a free trial for this companyGroup and this app
        // if ($app->usesSubscriptions()) {
        //     $companiesGroup->startFreeTrial(
        //         $companiesGroup,
        //         $company,
        //         $branch
        //     );
        // }
    }
}
