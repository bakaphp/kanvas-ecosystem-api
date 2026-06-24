<?php

declare(strict_types=1);

namespace Tests\Connectors\ProductEnrichment;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ProductEnrichment\Agents\ProductEnrichmentAgent;
use Kanvas\Connectors\ProductEnrichment\Services\ProductEnrichmentAgentService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Tests\TestCase;

class ProductEnrichmentAgentServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    public function testResolvesAnAppEnrichmentAgentByType(): void
    {
        [$app] = $this->makeEnrichmentAgent();

        // Contract: returns an agent of the enrichment type for this app (not a
        // specific id — an app may have more than one).
        $resolved = ProductEnrichmentAgentService::resolveAgent($app);

        $this->assertSame(ProductEnrichmentAgent::class, $resolved->type->handler);
        $this->assertSame($app->getId(), $resolved->apps_id);
    }

    public function testResolvesByExplicitAgentIdWithinApp(): void
    {
        [$app, $agent] = $this->makeEnrichmentAgent();

        $this->assertSame(
            $agent->getId(),
            ProductEnrichmentAgentService::resolveAgent($app, $agent->getId())->getId(),
        );
    }

    public function testThrowsForAgentIdNotInThisApp(): void
    {
        $this->expectException(ValidationException::class);

        ProductEnrichmentAgentService::resolveAgent(app(Apps::class), 999999999);
    }

    /**
     * @return array{0: Apps, 1: Agent}
     */
    private function makeEnrichmentAgent(): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $type = AgentType::factory()
            ->withAppId($app->getId())
            ->create(['handler' => ProductEnrichmentAgent::class]);

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $type->getId(), 'user_id' => $user->getId()]);

        return [$app, $agent];
    }
}
