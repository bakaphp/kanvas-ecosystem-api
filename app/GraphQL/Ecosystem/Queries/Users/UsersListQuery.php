<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Users;

use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Kanvas\Users\Repositories\UsersRepository;

class UsersListQuery
{
    /**
     * Get user from the current company.
     *
     * @param mixed $rootValue
     */
    public function getFromCurrentCompany($rootValue, array $request): Users
    {
        return UsersRepository::getUserOfCompanyById(
            auth()->user()->getCurrentCompany(),
            (int) $request['id']
        );
    }

    public function getByDisplayNameFromApp($rootValue, array $request): Users
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $displayname = $request['displayname'];
        if (! $user->isAppOwner()) {
            $displayname = $user->displayname;
        }

        return UsersAssociatedApps::where('displayname', $displayname)
            ->fromApp($app)
            ->firstOrFail()
            ->user;
    }
}
