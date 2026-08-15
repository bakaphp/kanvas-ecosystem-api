<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateDealTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetDealRefTool;
use Tests\TestCase;

class GetDealRefToolTest extends TestCase
{
    public function testReturnsDealDetail(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'firstname' => 'Orlanda',
            'lastname' => 'Kest',
        ]);

        $title = 'Deal ' . uniqid();
        $created = new CreateDealTool($app, $company, $user)
            ->__invoke(title: $title, description: 'annual plan', people_id: $people->getId());

        $result = $this->withTenant(new GetDealRefTool())->__invoke(deal_id: (int) $created['deal_id']);

        $this->assertSame((int) $created['deal_id'], $result['deal_id']);
        $this->assertSame($title, $result['title']);
        $this->assertSame($people->getId(), $result['people']['id']);
    }

    public function testHallucinatedDealIdReturnsError(): void
    {
        $result = $this->withTenant(new GetDealRefTool())->__invoke(deal_id: 999999999);

        $this->assertSame('error', $result['status']);
    }

    /**
     * Deal tools resolve their deal against the tenant on their context, so a bare instance
     * (no withContext) intentionally resolves nothing — mirror what the agent wiring does.
     *
     * @template T of object
     *
     * @param T $tool
     *
     * @return T
     */
    private function withTenant(object $tool): object
    {
        $user = auth()->user();

        return $tool->withContext(app(Apps::class), $user->getCurrentCompany(), $user);
    }
}
