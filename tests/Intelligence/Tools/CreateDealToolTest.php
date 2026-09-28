<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateDealTool;
use Tests\TestCase;

class CreateDealToolTest extends TestCase
{
    public function testCreatesDealWithTitleOnly(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $title = 'Opportunity ' . uniqid();

        $result = new CreateDealTool($app, $company, $user)
            ->__invoke(title: $title, description: 'Wants the annual plan');

        $this->assertSame('success', $result['status']);
        $this->assertArrayHasKey('deal_id', $result);

        $deal = Deal::getById((int) $result['deal_id']);
        $this->assertSame($title, $deal->title);
        $this->assertSame($app->getId(), $deal->apps_id);
        $this->assertSame($company->getId(), $deal->companies_id);
    }

    public function testCreatesDealLinkedToLead(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'title' => 'Lead ' . uniqid(),
        ]);

        $result = new CreateDealTool($app, $company, $user)
            ->__invoke(title: 'Deal for lead', leads_id: $lead->getId());

        $this->assertSame('success', $result['status']);

        $deal = Deal::getById((int) $result['deal_id']);
        $this->assertSame($lead->getId(), $deal->leads_id);
    }
}
