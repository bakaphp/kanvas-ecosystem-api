<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Companies;

use Bouncer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\B2BSettingsEnums;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Users\Actions\AddAdminsToCompanyAction;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class AddAdminsToCompanyActionTest extends TestCase
{
    protected Apps $testApp;
    protected Users $testUser;
    protected Companies $testCompany;
    protected CompaniesBranches $testBranch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testApp = app(Apps::class);
        $this->testUser = Auth::user();
        $this->testCompany = $this->testUser->getCurrentCompany();
        $this->testBranch = $this->testCompany->branches()->first();
    }

    public function testGetAdminEmails()
    {
        // Create test users
        $adminUser1 = $this->createUser();
        $adminUser2 = $this->createUser();
        $adminUser3 = $this->createUser();

        // Assign OWNER role to users to make them app admins
        Bouncer::scope()->to(RolesEnums::getScope($this->testApp));
        Bouncer::assign(RolesEnums::OWNER->value)->to($adminUser1);
        Bouncer::assign(RolesEnums::OWNER->value)->to($adminUser2);
        Bouncer::assign(RolesEnums::OWNER->value)->to($adminUser3);

        // Mock app setting with comma-separated emails (only include first two users)
        $mainAdminEmails = $adminUser1->email . ', ' . $adminUser2->email . ', nonexistent@example.com';

        $this->testApp->set(B2BSettingsEnums::B2B_COMPANY_ADMIN_EMAILS->getValue(), $mainAdminEmails);

        $action = new AddAdminsToCompanyAction(
            $this->testApp,
            $this->testUser,
            $this->testCompany,
            $this->testBranch
        );

        $result = $action->getAdmins($this->testApp);

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertEquals(2, $result->count());
        $this->assertTrue($result->contains('email', $adminUser1->email));
        $this->assertTrue($result->contains('email', $adminUser2->email));
        $this->assertFalse($result->contains('email', $adminUser3->email));
    }
}
