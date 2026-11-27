<?php

declare(strict_types=1);

namespace Tests\Traits;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAppAction;
use Kanvas\Users\Actions\AssignCompanyAction;
use Kanvas\Users\Models\Users;
use Silber\Bouncer\BouncerFacade as Bouncer;

/**
 * Members of the acting user's company with a chosen role. A user made through the signup action
 * becomes an admin of the app, so it cannot stand in for an ordinary employee.
 */
trait MakesEmployees
{
    protected function makeEmployee(): Users
    {
        return $this->makeMember(RolesEnums::USER);
    }

    /**
     * An administrator who is a person. The acting test user cannot stand in for one: in CI it is also
     * the test company's shared AI agent user, which every "is a person asking?" check treats as an AI.
     */
    protected function makeAdmin(): Users
    {
        $admin = $this->makeMember(RolesEnums::USER);

        // The role is read through Bouncer, inside the app's scope, and cached per user.
        Bouncer::scope()->onceTo(RolesEnums::getScope(app(Apps::class)), fn () => $admin->assign(RolesEnums::ADMIN->value));
        Bouncer::refreshFor($admin);

        return $admin;
    }

    private function makeMember(RolesEnums $role): Users
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        // The factory's display name is a dictionary word, shared with profiles other tests left
        // behind, so an @mention of it would resolve to those users as well.
        $user = Users::factory()->create(['displayname' => 'member' . fake()->unique()->lexify('??????')]);
        new RegisterUsersAppAction($user, $app)->execute((string) $user->password);

        new AssignCompanyAction(
            $user,
            $company->branch ?? $company->branches()->first(),
            RolesRepository::getByNameFromCompany($role->value, $company, $app),
            $app,
        )->execute();

        return $user;
    }
}
