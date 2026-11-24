<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions\ProviderAdmin;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Enums\MovipassRolesEnum;
use Kanvas\Connectors\Movipass\Support\ProviderAdminGuard;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Users\Actions\CreateInviteAction;
use Kanvas\Users\DataTransferObject\Invite as InviteData;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersInvite;

class InviteProviderUserAction
{
    public function __construct(
        protected readonly Users $actor,
        protected readonly Companies $company,
        protected readonly MovipassRolesEnum $role,
        protected readonly string $email,
        protected readonly ?string $firstname,
        protected readonly ?string $lastname,
        protected readonly Apps $app,
    ) {
    }

    public function execute(): UsersInvite
    {
        return ProviderAdminGuard::inAppScope($this->app, function (): UsersInvite {
            ProviderAdminGuard::assertCompanyInApp($this->company, $this->app);
            ProviderAdminGuard::assertMayHandOut($this->actor, $this->role, $this->app);

            $branch = $this->company->defaultBranch()->first();

            if ($branch === null) {
                throw new ValidationException("Company {$this->company->name} has no default branch.");
            }

            $role = ProviderAdminGuard::roleFor($this->role, $this->app);

            return new CreateInviteAction(
                inviteDto: new InviteData(
                    app: $this->app,
                    companyBranch: $branch,
                    role_id: (int) $role->id,
                    email: Str::lowerTrim($this->email),
                    firstname: Str::trimToNull($this->firstname),
                    lastname: Str::trimToNull($this->lastname),
                    description: null,
                ),
                user: $this->actor,
                actorManagesAllCompanies: true,
            )->execute();
        });
    }
}
