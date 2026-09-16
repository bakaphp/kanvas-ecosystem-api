<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Salesforce\Actions\PushOrganizationDeleteAction;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Organizations\Models\Organization;
use Tests\Connectors\Traits\HasSalesforceConfiguration;
use Tests\TestCase;

final class PushOrganizationDeleteActionTest extends TestCase
{
    use DatabaseTransactions;
    use HasSalesforceConfiguration;

    public function testDeletesAccountWhenExternalIdExists(): void
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
        $organization->set(CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value, '001xx000003DHP0AAA');

        $this->fakeSalesforceOAuth();
        Http::fake([
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Account/001xx000003DHP0AAA' => Http::response([], 204),
        ]);

        $result = new PushOrganizationDeleteAction($organization)->execute();

        $this->assertTrue($result);
        Http::assertSent(function ($request) {
            return $request->url() === self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Account/001xx000003DHP0AAA'
                && $request->method() === 'DELETE';
        });
    }

    public function testIsANoOpWhenTheOrganizationWasNeverSynced(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->configureSalesforce($company);

        $organization = Organization::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'name' => 'Never Synced Corp',
        ]);

        $this->fakeSalesforceOAuth();
        Http::fake();

        $result = new PushOrganizationDeleteAction($organization)->execute();

        $this->assertFalse($result);
        Http::assertNothingSent();
    }
}
