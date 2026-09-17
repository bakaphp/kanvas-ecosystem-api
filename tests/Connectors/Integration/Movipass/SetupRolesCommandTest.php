<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Bouncer;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\MovipassRolesEnum;
use Silber\Bouncer\Database\Role;
use Tests\TestCase;

final class SetupRolesCommandTest extends TestCase
{
    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->artisan('kanvas:movipass-setup-roles', ['app_id' => $this->kanvasApp->getId()])->assertSuccessful();
        Bouncer::scope()->to(RolesEnums::getScope($this->kanvasApp));
    }

    public function testParkingRolesExistWithTheirGrants(): void
    {
        $manager = $this->abilitiesOf(MovipassRolesEnum::PARKING_MANAGER);
        $operator = $this->abilitiesOf(MovipassRolesEnum::PARKING_OPERATOR);

        foreach (['list-orders', 'view-order', 'update-orders', 'correct-plate', 'add-observations', 'associate-payment', 'relocate'] as $shared) {
            $this->assertContains($shared, $manager);
            $this->assertContains($shared, $operator);
        }

        foreach (['configure-company', 'order-reports', 'cancel-orders', 'adjust-amount', 'download-orders'] as $managerOnly) {
            $this->assertContains($managerOnly, $manager);
            $this->assertNotContains($managerOnly, $operator);
        }

        foreach (['wallet-configure', 'recharge-bulk', 'admin-reverse-transition'] as $neither) {
            $this->assertNotContains($neither, $manager);
            $this->assertNotContains($neither, $operator);
        }
    }

    public function testRerunRevokesAGrantThatLeftTheMatrix(): void
    {
        // Bouncer::allow is additive; the command must also disallow so the matrix is the truth.
        Bouncer::allow(MovipassRolesEnum::PARKING_OPERATOR->value)->to('wallet-configure');
        $this->assertContains('wallet-configure', $this->abilitiesOf(MovipassRolesEnum::PARKING_OPERATOR));

        $this->artisan('kanvas:movipass-setup-roles', ['app_id' => $this->kanvasApp->getId()])->assertSuccessful();
        Bouncer::scope()->to(RolesEnums::getScope($this->kanvasApp));

        $this->assertNotContains('wallet-configure', $this->abilitiesOf(MovipassRolesEnum::PARKING_OPERATOR));
    }

    /** @return list<string> */
    private function abilitiesOf(MovipassRolesEnum $role): array
    {
        return Role::where('name', $role->value)
            ->where('scope', RolesEnums::getScope($this->kanvasApp))
            ->firstOrFail()
            ->getAbilities()
            ->pluck('name')
            ->all();
    }
}
