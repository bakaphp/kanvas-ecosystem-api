<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Salesforce\Actions\PushDealAction;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Deals\Actions\CreateDealAction;
use Kanvas\Guild\Deals\DataTransferObject\Deal as DealData;
use Kanvas\Guild\Organizations\Models\Organization;
use Tests\Connectors\Traits\HasSalesforceConfiguration;
use Tests\TestCase;

final class PushDealActionTest extends TestCase
{
    use DatabaseTransactions;
    use HasSalesforceConfiguration;

    public function testCreatesOpportunityWhenNoExternalIdExists(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->configureSalesforce($company);

        $deal = new CreateDealAction(
            new DealData(
                app: $app,
                company: $company,
                user: $user,
                title: 'Big Opportunity',
            ),
            runWorkflow: false,
        )->execute();

        $this->fakeSalesforceOAuth();
        Http::fake([
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Opportunity' => Http::response([
                'id' => '006xx000004TmXtAAK',
                'success' => true,
            ], 201),
        ]);

        $result = new PushDealAction($deal)->execute();

        $this->assertSame('006xx000004TmXtAAK', $result['id']);
        $this->assertSame('006xx000004TmXtAAK', $deal->get(CustomFieldEnum::SALESFORCE_OPPORTUNITY_ID->value));
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

        $deal = new CreateDealAction(
            new DealData(
                app: $app,
                company: $company,
                user: $user,
                title: 'Big Opportunity',
                organization: $organization,
            ),
            runWorkflow: false,
        )->execute();

        $this->fakeSalesforceOAuth();
        Http::fake([
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Account' => Http::response([
                'id' => '001xx000003DHP0AAA',
                'success' => true,
            ], 201),
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Opportunity' => Http::response([
                'id' => '006xx000004TmXtAAK',
                'success' => true,
            ], 201),
        ]);

        new PushDealAction($deal)->execute();

        $this->assertSame('001xx000003DHP0AAA', $organization->get(CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value));

        Http::assertSent(function ($request) {
            return $request->url() === self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Opportunity'
                && $request['AccountId'] === '001xx000003DHP0AAA';
        });
    }
}
