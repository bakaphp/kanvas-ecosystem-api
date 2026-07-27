<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Salesforce\Actions\ResolveDuplicateOrganizationAction;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Organizations\Models\Organization;
use Tests\Connectors\Traits\HasSalesforceConfiguration;
use Tests\TestCase;

final class ResolveDuplicateOrganizationActionTest extends TestCase
{
    use DatabaseTransactions;
    use HasSalesforceConfiguration;

    public function testAdoptedIdPushesTargetAndDeletesNothing(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->configureSalesforce($company);

        $source = $this->seedOrganization($app, $company, 'Source Corp');
        $source->set(CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value, '001xxSOURCE');

        // Target has never been synced with Salesforce — it will adopt source's id during merge.
        $target = $this->seedOrganization($app, $company, 'Target Corp');

        $this->fakeSalesforceOAuth();
        Http::fake([
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Account/001xxSOURCE' => Http::sequence()
                ->push(['Id' => '001xxSOURCE'], 200)
                ->push([], 204),
        ]);

        new ResolveDuplicateOrganizationAction($source, $target)->execute();

        Http::assertSent(function ($request) {
            return $request->url() === self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Account/001xxSOURCE'
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

        $source = $this->seedOrganization($app, $company, 'Source Corp');
        $source->set(CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value, '001xxSOURCE');

        $target = $this->seedOrganization($app, $company, 'Target Corp');
        $target->set(CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value, '001xxTARGET');

        $this->fakeSalesforceOAuth();
        Http::fake([
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Account/001xxTARGET' => Http::sequence()
                ->push(['Id' => '001xxTARGET'], 200)
                ->push([], 204),
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Account/001xxSOURCE' => Http::response([], 204),
        ]);

        $result = new ResolveDuplicateOrganizationAction($source, $target)->execute();

        $this->assertSame('001xxTARGET', $result->get(CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value));

        Http::assertSent(function ($request) {
            return $request->url() === self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Account/001xxTARGET'
                && $request->method() === 'PATCH';
        });
        Http::assertSent(function ($request) {
            return $request->url() === self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Account/001xxSOURCE'
                && $request->method() === 'DELETE';
        });
    }

    public function testNoSalesforceIdOnEitherSideSkipsSalesforceEntirely(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        $source = $this->seedOrganization($app, $company, 'Source Corp');
        $target = $this->seedOrganization($app, $company, 'Target Corp');

        Http::fake();

        new ResolveDuplicateOrganizationAction($source, $target)->execute();

        Http::assertNothingSent();
    }

    private function seedOrganization(AppInterface $app, CompanyInterface $company, string $name): Organization
    {
        return Organization::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => static::$cachedUser->getId(),
            'name' => $name,
            'address' => '',
            'total_employees' => 0,
        ]);
    }
}
