<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Salesforce\Actions\ResolveDuplicatePeopleAction;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Tests\Connectors\Traits\HasSalesforceConfiguration;
use Tests\TestCase;

final class ResolveDuplicatePeopleActionTest extends TestCase
{
    use DatabaseTransactions;
    use HasSalesforceConfiguration;

    public function testAdoptedIdPushesTargetAndDeletesNothing(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->configureSalesforce($company);

        $source = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();
        $source->set(CustomFieldEnum::SALESFORCE_CONTACT_ID->value, '003xxSOURCE');

        // Target has never been synced with Salesforce — it will adopt source's id during merge.
        $target = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();

        $this->fakeSalesforceOAuth();
        Http::fake([
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Contact/003xxSOURCE' => Http::sequence()
                ->push(['Id' => '003xxSOURCE'], 200)
                ->push([], 204),
        ]);

        new ResolveDuplicatePeopleAction($source, $target)->execute();

        Http::assertSent(function ($request) {
            return $request->url() === self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Contact/003xxSOURCE'
                && $request->method() === 'PATCH';
        });
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    }

    public function testRealConflictPushesTargetsOwnIdAndDeletesSource(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->configureSalesforce($company);

        $source = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();
        $source->set(CustomFieldEnum::SALESFORCE_CONTACT_ID->value, '003xxSOURCE');

        $target = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();
        $target->set(CustomFieldEnum::SALESFORCE_CONTACT_ID->value, '003xxTARGET');

        $this->fakeSalesforceOAuth();
        Http::fake([
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Contact/003xxTARGET' => Http::sequence()
                ->push(['Id' => '003xxTARGET'], 200)
                ->push([], 204),
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Contact/003xxSOURCE' => Http::response([], 204),
        ]);

        $result = new ResolveDuplicatePeopleAction($source, $target)->execute();

        $this->assertSame('003xxTARGET', $result->get(CustomFieldEnum::SALESFORCE_CONTACT_ID->value));

        Http::assertSent(function ($request) {
            return $request->url() === self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Contact/003xxTARGET'
                && $request->method() === 'PATCH';
        });
        Http::assertSent(function ($request) {
            return $request->url() === self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Contact/003xxSOURCE'
                && $request->method() === 'DELETE';
        });
    }

    public function testNoSalesforceIdOnEitherSideSkipsSalesforceEntirely(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        $source = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();
        $target = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();

        Http::fake();

        new ResolveDuplicatePeopleAction($source, $target)->execute();

        Http::assertNothingSent();
    }
}
