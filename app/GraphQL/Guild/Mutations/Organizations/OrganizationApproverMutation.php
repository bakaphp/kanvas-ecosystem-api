<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Organizations;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Organizations\Actions\AddApproverToOrganizationAction;
use Kanvas\Guild\Organizations\Actions\LinkApproverEmailToOrganizationAction;
use Kanvas\Guild\Organizations\Actions\RemoveApproverFromOrganizationAction;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationApprover;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class OrganizationApproverMutation
{
    use ResolvesActingContext;

    public function add(mixed $rootValue, array $request): OrganizationApprover
    {
        $input = $request['input'];
        $organization = $this->organizationFromInput($input);

        if ($this->hasEmail($input)) {
            return new LinkApproverEmailToOrganizationAction($organization, trim((string) $input['email']))->execute();
        }

        return new AddApproverToOrganizationAction($organization, $this->userFromInput($input))->execute();
    }

    public function remove(mixed $rootValue, array $request): bool
    {
        $input = $request['input'];
        $organization = $this->organizationFromInput($input);

        $user = $this->hasEmail($input)
            ? Users::query()->where('email', trim((string) $input['email']))->first()
            : $this->userFromInput($input);

        return $user !== null && new RemoveApproverFromOrganizationAction($organization, $user)->execute();
    }

    /**
     * organization_approvers carries no apps_id/companies_id of its own — this tenant-scoped lookup is
     * the only thing standing between an organization_id from request input and another tenant's rows.
     */
    private function organizationFromInput(array $input): Organization
    {
        $ctx = $this->actingContext();

        /** @var Organization $organization */
        $organization = Organization::getByIdFromCompanyApp((int) $input['organization_id'], $ctx->company, $ctx->app);

        $this->assertExactlyOneApproverReference($input);

        return $organization;
    }

    /**
     * Linking by id must not be a way to attach an arbitrary global user as an approver — they have to
     * already be a user of this app, the same bar the email path enforces by associating them.
     */
    private function userFromInput(array $input): Users
    {
        /** @var Users $user */
        $user = Users::getById((int) $input['users_id']);

        UsersRepository::belongsToThisApp($user, $this->actingContext()->app);

        return $user;
    }

    private function hasEmail(array $input): bool
    {
        return trim((string) ($input['email'] ?? '')) !== '';
    }

    private function assertExactlyOneApproverReference(array $input): void
    {
        $hasUser = trim((string) ($input['users_id'] ?? '')) !== '';

        if ($hasUser === $this->hasEmail($input)) {
            throw new ValidationException('Pass exactly one of users_id or email.');
        }
    }
}
