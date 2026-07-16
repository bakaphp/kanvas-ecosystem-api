<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Salesforce\Actions\SyncLeadToSalesforceAction;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\Connectors\Traits\HasSalesforceConfiguration;
use Tests\TestCase;

final class SyncLeadToSalesforceActionTest extends TestCase
{
    use DatabaseTransactions;
    use HasSalesforceConfiguration;

    public function testCreatesLeadWhenNoExternalIdExists(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->configureSalesforce($company);

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();

        $this->fakeSalesforceOAuth();
        Http::fake([
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Lead' => Http::response([
                'id' => '00Qxx0000004C92AAE',
                'success' => true,
            ], 201),
        ]);

        $result = new SyncLeadToSalesforceAction($app, $lead)->execute();

        $this->assertSame('00Qxx0000004C92AAE', $result['id']);
        $this->assertSame('00Qxx0000004C92AAE', $lead->get(CustomFieldEnum::SALESFORCE_LEAD_ID->value));

        Http::assertSent(function ($request) {
            return $request->url() === self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Lead'
                && $request->method() === 'POST';
        });
    }

    public function testUpdatesLeadWhenExternalIdAlreadyExists(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->configureSalesforce($company);

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();
        $lead->set(CustomFieldEnum::SALESFORCE_LEAD_ID->value, '00Qxx0000004C92AAE');

        $this->fakeSalesforceOAuth();
        Http::fake([
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Lead/00Qxx0000004C92AAE' => Http::sequence()
                ->push(['Id' => '00Qxx0000004C92AAE'], 200)
                ->push([], 204),
        ]);

        $result = new SyncLeadToSalesforceAction($app, $lead)->execute();

        $this->assertSame('00Qxx0000004C92AAE', $result['id']);

        Http::assertSent(function ($request) {
            return $request->url() === self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/Lead/00Qxx0000004C92AAE'
                && $request->method() === 'PATCH';
        });
    }
}
