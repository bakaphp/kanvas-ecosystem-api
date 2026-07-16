<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Salesforce\Actions\SyncLeadToSalesforceAction;
use Kanvas\Connectors\Salesforce\Client;
use Kanvas\Connectors\Salesforce\Enums\ConfigurationEnum;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

/**
 * Hits a real Salesforce org — no Http::fake() here, unlike every other test in this directory.
 * Requires a Connected App + refresh token from a Salesforce Developer Org, set in .env (the base
 * TestCase loads .env, not .env.testing — see tests/CLAUDE.md):
 *
 *   TEST_SALESFORCE_CLIENT_ID=...
 *   TEST_SALESFORCE_CLIENT_SECRET=...
 *   TEST_SALESFORCE_REFRESH_TOKEN=...
 *   TEST_SALESFORCE_LOGIN_URL=https://login.salesforce.com   # or https://test.salesforce.com for a sandbox
 *
 * Skipped in CI and whenever those variables aren't set, so it never fires accidental real API
 * calls — same pattern as tests/Connectors/Integration/NetSuite/CustomerTest.php and
 * tests/Connectors/Integration/PasoRapido/PasoRapidoTest.php.
 */
final class SalesforceLiveTest extends TestCase
{
    use DatabaseTransactions;

    private ?string $createdLeadId = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Salesforce live integration test is skipped in CI.');
        }

        if (! env('TEST_SALESFORCE_CLIENT_ID') || ! env('TEST_SALESFORCE_CLIENT_SECRET') || ! env('TEST_SALESFORCE_REFRESH_TOKEN')) {
            $this->markTestSkipped(
                'Set TEST_SALESFORCE_CLIENT_ID, TEST_SALESFORCE_CLIENT_SECRET and TEST_SALESFORCE_REFRESH_TOKEN in .env '
                . '(a Connected App + refresh token from a Salesforce Developer Org) to run this test.'
            );
        }
    }

    protected function tearDown(): void
    {
        if ($this->createdLeadId !== null) {
            $company = static::$cachedUser->getCurrentCompany();
            Client::getInstance(app(Apps::class), $company)->delete('Lead', $this->createdLeadId);
        }

        parent::tearDown();
    }

    public function testCreatesAndReadsBackARealLeadInSalesforce(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        $company->set(ConfigurationEnum::CLIENT_ID->value, env('TEST_SALESFORCE_CLIENT_ID'));
        $company->set(ConfigurationEnum::CLIENT_SECRET->value, env('TEST_SALESFORCE_CLIENT_SECRET'));
        $company->set(ConfigurationEnum::REFRESH_TOKEN->value, env('TEST_SALESFORCE_REFRESH_TOKEN'));
        $company->set(ConfigurationEnum::LOGIN_URL->value, env('TEST_SALESFORCE_LOGIN_URL', 'https://login.salesforce.com'));

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();

        $result = new SyncLeadToSalesforceAction($app, $lead)->execute();
        $this->createdLeadId = $result['id'] ?? null;

        $this->assertNotEmpty(
            $this->createdLeadId,
            'Salesforce did not return a Lead id — check the Connected App scopes/credentials.'
        );
        $this->assertSame($this->createdLeadId, $lead->get(CustomFieldEnum::SALESFORCE_LEAD_ID->value));

        $remoteLead = Client::getInstance($app, $company)->find('Lead', $this->createdLeadId);

        $this->assertNotNull($remoteLead, 'Could not read the Lead back from Salesforce right after creating it.');
        $this->assertSame($lead->people->lastname, $remoteLead['LastName'] ?? null);
    }
}
