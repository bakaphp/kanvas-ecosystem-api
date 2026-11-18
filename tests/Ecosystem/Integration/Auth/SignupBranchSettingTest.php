<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Auth;

use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Services\AuthenticationService;
use Kanvas\Enums\AppSettingsEnums;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The signup branch setting holds an id or a uuid. A uuid compared to the integer id column used to
 * be cast by MySQL to its leading digits and sign users up into an unrelated branch.
 * Serial: it rewrites an app setting every parallel process reads.
 */
#[Group('serial')]
final class SignupBranchSettingTest extends TestCase
{
    public function testTheSettingResolvesByIdOrUuidAndNeverByCastingAUuid(): void
    {
        $app = app(Apps::class);
        $key = AppSettingsEnums::GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY->getValue();
        $previous = $app->get($key);
        $branch = auth()->user()->getCurrentCompany()->defaultBranch;

        try {
            $app->set($key, $branch->getId());
            $this->assertSame($branch->getId(), AuthenticationService::getAppDefaultAssignCompanyBranch($app)?->getId());

            $app->set($key, $branch->uuid);
            $this->assertSame($branch->getId(), AuthenticationService::getAppDefaultAssignCompanyBranch($app)?->getId());

            // Starts with "1": the old lookup read it as branch 1.
            $app->set($key, '1aaaaaaa-0000-0000-0000-000000000000');
            $this->assertNull(AuthenticationService::getAppDefaultAssignCompanyBranch($app));
        } finally {
            $previous === null ? $app->del($key) : $app->set($key, $previous);
        }
    }
}
