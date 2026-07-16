<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Salesforce\Actions\SyncOrganizationToSalesforceAction;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Organizations\Models\Organization;
use Tests\Connectors\Traits\HasSalesforceConfiguration;
use Tests\TestCase;

final class SyncOrganizationToSalesforceActionTest extends TestCase
{
    use DatabaseTransactions;
    use HasSalesforceConfiguration;

    public function testCreatesAccountWhenNoExternalIdExists(): void
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
            'total_employees' => 50,
        ]);

        $this->fakeSalesforceOAuth();
        Http::fake([
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Account' => Http::response([
                'id' => '001xx000003DHP0AAA',
                'success' => true,
            ], 201),
        ]);

        $result = new SyncOrganizationToSalesforceAction($app, $organization)->execute();

        $this->assertSame('001xx000003DHP0AAA', $result['id']);
        $this->assertSame('001xx000003DHP0AAA', $organization->get(CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value));

        Http::assertSent(function ($request) {
            return $request->url() === self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Account'
                && $request->method() === 'POST'
                && $request['Name'] === 'Acme Corp';
        });
    }

    public function testUpdatesAccountWhenExternalIdAlreadyExists(): void
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
            'total_employees' => 50,
        ]);
        $organization->set(CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value, '001xx000003DHP0AAA');

        $this->fakeSalesforceOAuth();
        Http::fake([
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Account/001xx000003DHP0AAA' => Http::sequence()
                ->push(['Id' => '001xx000003DHP0AAA', 'Name' => 'Acme Corp'], 200)
                ->push([], 204),
        ]);

        $result = new SyncOrganizationToSalesforceAction($app, $organization)->execute();

        $this->assertSame('001xx000003DHP0AAA', $result['id']);

        Http::assertSent(function ($request) {
            return $request->url() === self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Account/001xx000003DHP0AAA'
                && $request->method() === 'PATCH';
        });
    }
}
