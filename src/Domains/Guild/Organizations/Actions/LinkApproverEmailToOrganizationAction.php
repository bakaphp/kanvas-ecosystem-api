<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Actions;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Kanvas\Auth\Actions\RegisterUsersAppAction;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationApprover;
use Kanvas\Users\Models\Users;

/**
 * Links an approver by email, for the case where finance has a spreadsheet of addresses and no Kanvas
 * user ids. Reuses an existing Users row with that email when there is one; otherwise creates a
 * minimal, unonboarded record just to hold the identity — no company, no welcome email, no workflow —
 * so it can be matched against a Slack profile by email like any other approver.
 *
 * Either way the user is associated with the organization's app. Without that row `getAppProfile()`
 * throws, which takes down any `approvers { user { email } }` query — the app profile is where the
 * User GraphQL type reads email and displayname from.
 */
class LinkApproverEmailToOrganizationAction
{
    public function __construct(
        protected readonly Organization $organization,
        protected readonly string $email,
    ) {
    }

    public function execute(): OrganizationApprover
    {
        $email = trim($this->email);

        $user = Users::query()->where('email', $email)->first() ?? $this->createMinimalUser($email);

        new RegisterUsersAppAction($user, $this->organization->app)->execute($user->password);

        return new AddApproverToOrganizationAction($this->organization, $user)->execute();
    }

    private function createMinimalUser(string $email): Users
    {
        $user = new Users();
        $user->email = $email;
        $user->password = Hash::make(Str::random(32));
        $user->displayname = explode('@', $email)[0];
        $user->default_company = 0;
        $user->user_active = 1;
        $user->status = 1;
        $user->saveOrFail();

        return $user;
    }
}
