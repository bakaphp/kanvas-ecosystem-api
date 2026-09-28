<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Salesforce\Actions\PushPeopleDeleteAction;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Tests\Connectors\Traits\HasSalesforceConfiguration;
use Tests\TestCase;

final class PushPeopleDeleteActionTest extends TestCase
{
    use DatabaseTransactions;
    use HasSalesforceConfiguration;

    public function testDeletesContactWhenExternalIdExists(): void
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
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Contact/003xx000004TmiQAAS' => Http::response([], 204),
        ]);

        $result = new PushPeopleDeleteAction($people)->execute();

        $this->assertTrue($result);
        Http::assertSent(function ($request) {
            return $request->url() === self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Contact/003xx000004TmiQAAS'
                && $request->method() === 'DELETE';
        });
    }

    public function testIsANoOpWhenThePeopleWasNeverSynced(): void
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
        Http::fake();

        $result = new PushPeopleDeleteAction($people)->execute();

        $this->assertFalse($result);
        Http::assertNothingSent();
    }
}
