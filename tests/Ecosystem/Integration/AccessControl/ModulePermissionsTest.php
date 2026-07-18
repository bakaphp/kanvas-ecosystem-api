<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\AccessControl;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class ModulePermissionsTest extends TestCase
{
    // The base TestCase shares a static cached user across every test. Without rolling back, one
    // method's direct grant persists onto that user and leaks into the next method (and, under
    // paratest, across parallel processes on the shared DB) — which is why a later role-based can()
    // check reads the wrong state and fails only when run alongside its siblings.
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql'];

    private const PERMISSION_VIEW = 'view-module-inventory';
    private const PERMISSION_CREATE = 'create-module-inventory';
    private const PERMISSION_EDIT = 'edit-module-inventory';
    private const PERMISSION_DELETE = 'delete-module-inventory';
    private const PERMISSION_MANAGE = 'manage-module-inventory';

    protected Users $user;
    protected Companies $company;
    protected Apps $appInstance;
    protected string $scope;

    protected function setUp(): void
    {
        parent::setUp();

        // Bouncer caches abilities per user; across this file's methods that cache goes stale after a
        // role/permission is granted, so a later can() reads pre-grant abilities and wrongly returns
        // false. Resolve every ability from the DB so each method sees the state it just set up.
        Bouncer::dontCache();

        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();
        $this->appInstance = app(Apps::class);
        $this->scope = RolesEnums::getScope($this->appInstance, $this->company);
    }

    public function testUserCanBeGrantedDirectPermission(): void
    {
        $this->grantPermissionToUser(self::PERMISSION_VIEW);

        $this->assertUserHasPermissions([self::PERMISSION_VIEW]);
    }

    public function testUserCannotAccessModuleWithoutPermission(): void
    {
        $inventoryModule = SystemModulesRepository::getByModelName('Kanvas\Inventory\Products\Models\Products');

        $this->assertFalse($this->user->canAccessModule($inventoryModule->getId()));
    }

    public function testRoleWithPermissionsCanBeAssignedToUser(): void
    {
        $role = $this->createRoleWithPermissions('Admin', [
            self::PERMISSION_VIEW,
            self::PERMISSION_CREATE,
            self::PERMISSION_EDIT,
            self::PERMISSION_DELETE,
        ]);

        $this->user->assign($role);

        $this->assertUserHasPermissions([
            self::PERMISSION_VIEW,
            self::PERMISSION_CREATE,
            self::PERMISSION_EDIT,
            self::PERMISSION_DELETE,
        ]);
    }

    private function grantPermissionToUser(string $permission): void
    {
        Bouncer::scope()->to($this->scope);
        $this->user->allow($permission);
    }

    private function createRoleWithPermissions(string $roleName, array $permissions): mixed
    {
        Bouncer::scope()->to($this->scope);

        $role = Bouncer::role()->firstOrCreate([
            'name' => $roleName,
            'scope' => $this->scope,
        ]);

        foreach ($permissions as $permission) {
            $role->allow($permission);
        }

        return $role;
    }

    private function assertUserHasPermissions(array $permissions): void
    {
        Bouncer::scope()->to($this->scope);

        foreach ($permissions as $permission) {
            $this->assertTrue(
                $this->user->can($permission),
                "User should have '{$permission}' permission"
            );
        }
    }
}
