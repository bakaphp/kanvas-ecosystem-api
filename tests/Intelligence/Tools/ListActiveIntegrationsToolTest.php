<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Neuron\Tools\Capability\ListActiveIntegrationsTool;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Models\Integrations;
use Tests\TestCase;

/**
 * What the tenant has actually connected, as opposed to what the platform owns.
 *
 * `capability_lookup` can only report "owned but unconfigured here" for the four connectors
 * `ConnectorReadinessService` lists by name, so for every other integration "not set up" and "does not
 * exist" are the same answer. That is how an agent reported the platform could not open pull requests
 * while `pidev` and `claude-agent` were active on its own company.
 */
class ListActiveIntegrationsToolTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow'];

    public function test_it_lists_what_the_company_has_switched_on(): void
    {
        $company = static::$cachedUser->getCurrentCompany();
        $integration = $this->connect($company, 'zzqq-connector-' . uniqid());

        $result = $this->list();

        $this->assertContains(
            $integration->name,
            array_column($result['integrations'], 'name'),
        );
        $this->assertSame(count($result['integrations']), $result['count']);
    }

    /** A switched-off integration is not reachable, so reporting it would be worse than omitting it. */
    public function test_an_inactive_integration_is_not_listed(): void
    {
        $company = static::$cachedUser->getCurrentCompany();
        $integration = $this->connect($company, 'zzqq-inactive-' . uniqid(), active: false);

        $this->assertNotContains(
            $integration->name,
            array_column($this->list()['integrations'], 'name'),
        );
    }

    /** `integration_companies` carries no apps_id — the company is the whole tenant boundary here. */
    public function test_another_companys_integration_is_not_visible(): void
    {
        $other = Companies::factory()->create();
        $integration = $this->connect($other, 'zzqq-foreign-' . uniqid());

        $this->assertNotContains(
            $integration->name,
            array_column($this->list()['integrations'], 'name'),
        );
    }

    /** A company that has connected nothing must say so plainly, not return an empty shrug. */
    public function test_a_company_with_nothing_connected_says_so(): void
    {
        $result = new ListActiveIntegrationsTool()
            ->withContext(app(Apps::class), Companies::factory()->create(), static::$cachedUser)
            ->__invoke();

        $this->assertSame(0, $result['count']);
        $this->assertStringContainsString('connected nothing yet', $result['note']);
    }

    /**
     * @return array<string, mixed>
     */
    private function list(): array
    {
        return new ListActiveIntegrationsTool()
            ->withContext(
                app(Apps::class),
                static::$cachedUser->getCurrentCompany(),
                static::$cachedUser,
            )
            ->__invoke();
    }

    private function connect(Companies $company, string $name, bool $active = true): Integrations
    {
        $integration = Integrations::create([
            'name' => $name,
            'apps_id' => 0,
            'handler' => 'Kanvas\\Connectors\\Fixture\\Handlers\\FixtureHandler',
            'is_deleted' => 0,
        ]);

        // `is_active` and `is_deleted` are not fillable on this model, so create() drops them
        // silently — set them after, or the row lands inactive and the assertion tests nothing.
        $link = IntegrationsCompany::create([
            'companies_id' => $company->getId(),
            'integrations_id' => $integration->getId(),
            'status_id' => 1,
            'region_id' => 1,
        ]);
        $link->is_active = $active ? 1 : 0;
        $link->is_deleted = 0;
        $link->saveQuietly();

        return $integration;
    }
}
