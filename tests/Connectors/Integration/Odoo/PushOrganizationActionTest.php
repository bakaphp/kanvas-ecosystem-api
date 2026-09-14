<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Odoo;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Odoo\Actions\PushOrganizationAction;
use Kanvas\Connectors\Odoo\Enums\CustomFieldEnum;
use Kanvas\Guild\Organizations\Models\Organization;
use Tests\Connectors\Traits\HasOdooConfiguration;
use Tests\TestCase;

final class PushOrganizationActionTest extends TestCase
{
    use DatabaseTransactions;
    use HasOdooConfiguration;

    public function testCreatesPartnerWhenNoExternalIdExists(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->configureOdoo($company, $app);

        $organization = Organization::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'name' => 'Acme Corp',
            'phone' => '5555550100',
        ]);

        $this->fakeOdooApi([
            'create:res.partner' => 501,
            'write:res.partner' => true,
        ]);

        $result = new PushOrganizationAction($organization)->execute();

        $this->assertSame(501, $result['id']);
        $this->assertSame('501', (string) $organization->get(CustomFieldEnum::ODOO_ACCOUNT_ID->value));
        $this->assertTrue($result['is_company']);
    }

    public function testUpdatesPartnerWhenExternalIdAlreadyExists(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->configureOdoo($company, $app);

        $organization = Organization::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'name' => 'Acme Corp',
            'phone' => '5555550100',
        ]);
        $organization->set(CustomFieldEnum::ODOO_ACCOUNT_ID->value, '501');

        $this->fakeOdooApi([
            'search_read:res.partner' => [['id' => 501]],
            'write:res.partner' => true,
        ]);

        $result = new PushOrganizationAction($organization)->execute();

        $this->assertSame('501', (string) $result['id']);
    }

    public function testDoesNotCreateAgainWhenTheExternalIdWasDeletedInOdoo(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->configureOdoo($company, $app);

        $organization = Organization::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'name' => 'Acme Corp',
        ]);
        $organization->set(CustomFieldEnum::ODOO_ACCOUNT_ID->value, '501');

        $this->fakeOdooApi([
            'search_read:res.partner' => [],
            'create:res.partner' => 502,
            'write:res.partner' => true,
        ]);

        $result = new PushOrganizationAction($organization)->execute();

        $this->assertSame(502, $result['id']);
        $this->assertSame('502', (string) $organization->get(CustomFieldEnum::ODOO_ACCOUNT_ID->value));
    }
}
