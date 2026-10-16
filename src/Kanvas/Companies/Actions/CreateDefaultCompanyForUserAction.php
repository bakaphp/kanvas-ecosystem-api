<?php

declare(strict_types=1);

namespace Kanvas\Companies\Actions;

use Baka\Contracts\CompanyInterface;
use Kanvas\Companies\DataTransferObject\Company as CompanyData;
use Kanvas\Users\Actions\AssignCompanyAction;
use Kanvas\Users\Models\Users;

/**
 * The default company a user gets the first time they have none in the current app context —
 * extracted from `CreateUserAction::createCompany()` so app creation can reuse the exact same
 * bootstrap instead of a second copy. `CompaniesObserver::created()` resolves the owning app via
 * `app(Apps::class)`, so the caller must have that container binding pointed at the right app
 * before calling `execute()`.
 *
 * Defaults to "{displayname}CP" (the self-registration naming) when $name is omitted; app creation
 * passes the new app's own name instead, so the owner's company reads like the business, not the
 * person who signed it up.
 */
class CreateDefaultCompanyForUserAction
{
    public function __construct(
        protected Users $user,
        protected ?string $name = null,
    ) {
    }

    public function execute(): CompanyInterface
    {
        $company = new CreateCompaniesAction(
            new CompanyData(
                user: $this->user,
                name: $this->name ?? $this->user->defaultCompanyName ?? $this->user->displayname . 'CP',
                email: $this->user->email,
            ),
        )->execute();

        $branch = $company->branch()->firstOrFail();

        $this->user->default_company = (int) $company->id;
        $this->user->default_company_branch = (int) $branch->id;
        $this->user->saveOrFail();

        new AssignCompanyAction($this->user, $branch)->execute();

        return $company;
    }
}
