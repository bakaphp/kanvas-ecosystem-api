<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Salesforce\Actions\PushLeadAction;
use Kanvas\Connectors\Salesforce\Client;
use Kanvas\Connectors\Salesforce\Enums\ConfigurationEnum;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Connectors\Salesforce\Enums\GrantTypeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

/**
 * Hits a real Salesforce org — no Http::fake() here, unlike every other test in this directory.
 * Set in .env (the base TestCase loads .env, not .env.testing — see tests/CLAUDE.md):
 *
 *   TEST_SALESFORCE_CLIENT_ID=...
 *   TEST_SALESFORCE_CLIENT_SECRET=...
 *   TEST_SALESFORCE_LOGIN_URL=...      # the org's own domain, e.g. https://gagroup.my.salesforce.com
 *
 * Plus ONE of these two, depending on how the org's Connected App is set up:
 *   TEST_SALESFORCE_GRANT_TYPE=refresh_token (default) + TEST_SALESFORCE_REFRESH_TOKEN=...
 *   TEST_SALESFORCE_GRANT_TYPE=client_credentials        (no refresh token needed at all)
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

        if (! env('TEST_SALESFORCE_CLIENT_ID') || ! env('TEST_SALESFORCE_CLIENT_SECRET')) {
            $this->markTestSkipped(
                'Set TEST_SALESFORCE_CLIENT_ID and TEST_SALESFORCE_CLIENT_SECRET in .env to run this test.'
            );
        }

        if ($this->resolveGrantType() === GrantTypeEnum::REFRESH_TOKEN && ! env('TEST_SALESFORCE_REFRESH_TOKEN')) {
            $this->markTestSkipped(
                'Set TEST_SALESFORCE_REFRESH_TOKEN in .env, or set TEST_SALESFORCE_GRANT_TYPE=client_credentials '
                . 'if the org\'s Connected App uses that flow instead, to run this test.'
            );
        }
    }

    private function resolveGrantType(): GrantTypeEnum
    {
        return GrantTypeEnum::tryFrom((string) env('TEST_SALESFORCE_GRANT_TYPE', '')) ?? GrantTypeEnum::REFRESH_TOKEN;
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

        $grantType = $this->resolveGrantType();

        $company->set(ConfigurationEnum::CLIENT_ID->value, env('TEST_SALESFORCE_CLIENT_ID'));
        $company->set(ConfigurationEnum::CLIENT_SECRET->value, env('TEST_SALESFORCE_CLIENT_SECRET'));
        $company->set(ConfigurationEnum::GRANT_TYPE->value, $grantType->value);
        $company->set(ConfigurationEnum::LOGIN_URL->value, env('TEST_SALESFORCE_LOGIN_URL', 'https://login.salesforce.com'));

        if ($grantType === GrantTypeEnum::REFRESH_TOKEN) {
            $company->set(ConfigurationEnum::REFRESH_TOKEN->value, env('TEST_SALESFORCE_REFRESH_TOKEN'));
        }

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();

        $result = new PushLeadAction($lead)->execute();
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
