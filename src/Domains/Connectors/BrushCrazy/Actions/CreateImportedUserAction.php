<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Actions;

use Kanvas\Auth\Actions\CreateUserAction;
use Kanvas\Users\Models\Users;
use Override;

/**
 * A sign-up's own steps (user row, app profile, role, company) without what a sign-up triggers: no
 * welcome email, no onboarding, no `registered` workflow. These are existing BrushCrazy customers
 * moving over, not people who just signed up; they hear about the new site in one launch email,
 * sent when the business chooses. The password in the input is already a bcrypt hash.
 */
class CreateImportedUserAction extends CreateUserAction
{
    #[Override]
    public function execute(): Users
    {
        $user = $this->createNewUser();

        return $this->joinApp($user);
    }

    /**
     * For an email that already has a Kanvas user from another app: it gets this app's profile,
     * with the BrushCrazy password, and joins the customers company.
     */
    public function joinApp(Users $user): Users
    {
        // AssignCompanyAction saves the user, and a saved user fires `updated`, which would push the
        // account straight back to BrushCrazy.
        $user->disableWorkflows();

        $this->registerUserInApp($user);
        $this->assignUserRole($user);
        $this->assignCompany($user);

        return $user;
    }
}
