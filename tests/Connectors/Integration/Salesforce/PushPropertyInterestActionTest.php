<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Salesforce\Actions\PushPropertyInterestAction;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Tests\Connectors\Traits\HasSalesforceConfiguration;
use Tests\TestCase;

final class PushPropertyInterestActionTest extends TestCase
{
    use DatabaseTransactions;
    use HasSalesforceConfiguration;

    public function testCreatesLocationContactWhenNoneExists(): void
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
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/query*' => Http::response(['records' => []], 200),
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Location_Contact__c' => Http::response(['id' => 'a01xx000003NEW1AAO'], 201),
        ]);

        $result = new PushPropertyInterestAction($people, 'a006g00000Gk2NiAAJ')->execute();

        $this->assertSame('a01xx000003NEW1AAO', $result);
        Http::assertSent(function ($request) {
            return $request->url() === self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Location_Contact__c'
                && $request->method() === 'POST'
                && $request['Location__c'] === 'a006g00000Gk2NiAAJ'
                && $request['Contact__c'] === '003xx000004TmiQAAS'
                && $request['Location_Contact_Type__c'] === 'Interested Party';
        });
    }

    public function testReusesExistingLocationContactInsteadOfDuplicating(): void
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
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/query*' => Http::response([
                'records' => [['Id' => 'a01xx000003EXISTING']],
            ], 200),
        ]);

        $result = new PushPropertyInterestAction($people, 'a006g00000Gk2NiAAJ')->execute();

        $this->assertSame('a01xx000003EXISTING', $result);
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), '/sobjects/Location_Contact__c') && $request->method() === 'POST';
        });
    }

    public function testIsANoOpWhenThePeopleHasNoSalesforceContactId(): void
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

        $result = new PushPropertyInterestAction($people, 'a006g00000Gk2NiAAJ')->execute();

        $this->assertNull($result);
        Http::assertNothingSent();
    }
}
