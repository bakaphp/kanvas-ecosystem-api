<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Odoo;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Odoo\Actions\PushPeopleAction;
use Kanvas\Connectors\Odoo\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Tests\Connectors\Traits\HasOdooConfiguration;
use Tests\TestCase;

final class PushPeopleActionTest extends TestCase
{
    use DatabaseTransactions;
    use HasOdooConfiguration;

    public function testCreatesPartnerWhenNoExternalIdExists(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->configureOdoo($company, $app);

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();

        $this->fakeOdooApi([
            'create:res.partner' => 601,
        ]);

        $result = new PushPeopleAction($people)->execute();

        $this->assertSame(601, $result['id']);
        $this->assertSame('601', (string) $people->get(CustomFieldEnum::ODOO_CONTACT_ID->value));
        $this->assertFalse($result['is_company']);
    }

    public function testUpdatesPartnerWhenExternalIdAlreadyExists(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->configureOdoo($company, $app);

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();
        $people->set(CustomFieldEnum::ODOO_CONTACT_ID->value, '601');

        $this->fakeOdooApi([
            'search_read:res.partner' => [['id' => 601]],
            'write:res.partner' => true,
        ]);

        $result = new PushPeopleAction($people)->execute();

        $this->assertSame('601', (string) $result['id']);
    }

    public function testSyncsOrganizationFirstWhenAccountIdIsMissing(): void
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

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();
        $organization->addPeople($people);

        $createdIds = [601, 602];
        $this->fakeOdooApi([
            'create:res.partner' => function () use (&$createdIds) {
                return array_shift($createdIds);
            },
            'write:res.partner' => true,
        ]);

        new PushPeopleAction($people)->execute();

        $this->assertSame('601', (string) $organization->get(CustomFieldEnum::ODOO_ACCOUNT_ID->value));
        $this->assertSame('602', (string) $people->get(CustomFieldEnum::ODOO_CONTACT_ID->value));
    }
}
