<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Salesforce\Actions\PushPeopleAction;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Tests\Connectors\Traits\HasSalesforceConfiguration;
use Tests\TestCase;

final class PushPeopleActionTest extends TestCase
{
    use DatabaseTransactions;
    use HasSalesforceConfiguration;

    public function testCreatesContactWhenNoExternalIdExists(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->configureSalesforce($company);

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();

        $this->fakeSalesforceOAuth();
        Http::fake([
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Contact' => Http::response([
                'id' => '003xx000004TmiQAAS',
                'success' => true,
            ], 201),
        ]);

        $result = new PushPeopleAction($people)->execute();

        $this->assertSame('003xx000004TmiQAAS', $result['id']);
        $this->assertSame('003xx000004TmiQAAS', $people->get(CustomFieldEnum::SALESFORCE_CONTACT_ID->value));
    }

    public function testUpdatesContactWhenExternalIdAlreadyExists(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->configureSalesforce($company);

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();
        $people->set(CustomFieldEnum::SALESFORCE_CONTACT_ID->value, '003xx000004TmiQAAS');

        $this->fakeSalesforceOAuth();
        Http::fake([
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Contact/003xx000004TmiQAAS' => Http::sequence()
                ->push(['Id' => '003xx000004TmiQAAS'], 200)
                ->push([], 204),
        ]);

        $result = new PushPeopleAction($people)->execute();

        $this->assertSame('003xx000004TmiQAAS', $result['id']);

        Http::assertSent(function ($request) {
            return $request->url() === self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Contact/003xx000004TmiQAAS'
                && $request->method() === 'PATCH';
        });
    }

    public function testSyncsOrganizationFirstWhenAccountIdIsMissing(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->configureSalesforce($company);

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

        $this->fakeSalesforceOAuth();
        Http::fake([
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Account' => Http::response([
                'id' => '001xx000003DHP0AAA',
                'success' => true,
            ], 201),
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Contact' => Http::response([
                'id' => '003xx000004TmiQAAS',
                'success' => true,
            ], 201),
        ]);

        new PushPeopleAction($people)->execute();

        $this->assertSame('001xx000003DHP0AAA', $organization->get(CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value));

        Http::assertSent(function ($request) {
            return $request->url() === self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Contact'
                && $request['AccountId'] === '001xx000003DHP0AAA';
        });
    }
}
