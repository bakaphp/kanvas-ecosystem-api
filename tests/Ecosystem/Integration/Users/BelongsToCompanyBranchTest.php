<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Users;

use Bouncer;
use Illuminate\Support\Facades\Auth;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\AppKey;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Users\Models\UsersAssociatedCompanies;
use Kanvas\Users\Repositories\UsersRepository;
use Tests\TestCase;

final class BelongsToCompanyBranchTest extends TestCase
{
    public function testThrowsWhenUserDoesNotBelongToBranch(): void
    {
        $user = Auth::user();

        $otherCompany = $this->createUser()->getCurrentCompany();
        $otherBranch = $otherCompany->branches()->first();

        $this->expectException(ModelNotFoundException::class);

        UsersRepository::belongsToCompanyBranch($user, $otherCompany, $otherBranch);
    }

    public function testAppOwnerCanBypassBranchMembership(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();

        $otherCompany = $this->createUser()->getCurrentCompany();
        $otherBranch = $otherCompany->branches()->first();

        // isAppOwner() requires an AppKey bound in the container AND the Owner role
        app()->instance(AppKey::class, $app->keys()->first());
        Bouncer::scope()->to(RolesEnums::getScope($app));
        $user->assign(RolesEnums::OWNER->value);

        $this->assertTrue($user->isAppOwner());

        $result = UsersRepository::belongsToCompanyBranch($user, $otherCompany, $otherBranch);

        $this->assertInstanceOf(UsersAssociatedCompanies::class, $result);
    }
}
