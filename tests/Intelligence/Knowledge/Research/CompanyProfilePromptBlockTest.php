<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge\Research;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Company\CompanyBrainAgent;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesAgent;
use Kanvas\Intelligence\Knowledge\Research\Services\CompanyProfileService;
use Tests\TestCase;

final class CompanyProfilePromptBlockTest extends TestCase
{
    use DatabaseTransactions;

    private ?Companies $company = null;

    protected function tearDown(): void
    {
        $this->company?->del(CompanyProfileService::SETTING_KEY);

        parent::tearDown();
    }

    public function testSystemUserAgentGetsTheBlockAndSalesAgentDoesNot(): void
    {
        $company = $this->company();
        new CompanyProfileService()->write($company, [
            'name' => 'Acme Chairs',
            'industry' => 'Office furniture',
            'offering' => 'Ergonomic chairs for open-plan offices',
            'sells_to' => 'Facilities managers',
            'locations' => ['Santo Domingo'],
            'languages' => ['es', 'en'],
            'tone' => 'Direct',
            'summary' => 'Acme makes chairs.',
        ]);

        $brain = new CompanyBrainAgent();
        $brain->setConfiguration(agent: $this->agent($company), user: auth()->user());
        $sales = new SalesAgent();
        $sales->setConfiguration(agent: $this->agent($company), user: auth()->user());

        $this->assertStringContainsString('## Company profile', $brain->instructions());
        $this->assertStringContainsString('Office furniture', $brain->instructions());
        $this->assertStringContainsString('Santo Domingo', $brain->instructions());
        $this->assertStringNotContainsString('## Company profile', $sales->instructions());
    }

    public function testNoProfileMeansNoBlock(): void
    {
        $this->assertSame('', CompanyProfileService::promptBlock($this->company()));
        $this->assertSame('', CompanyProfileService::promptBlock(null));
    }

    public function testWriteFillsEmptyColumnsOnly(): void
    {
        $company = $this->company();
        $company->website = 'https://keep.me';
        $company->phone = '';
        $company->saveOrFail();

        new CompanyProfileService()->write($company, [
            'name' => 'Acme',
            'website' => 'https://acme.example',
            'phone' => '+1 809 555 0100',
            'summary' => 'x',
        ]);

        $company->refresh();
        $this->assertSame('https://keep.me', $company->website);
        $this->assertSame('+1 809 555 0100', $company->phone);
        $this->assertSame('Acme', $company->get(CompanyProfileService::SETTING_KEY)['name']);
        $this->assertSame('website', $company->get(CompanyProfileService::SETTING_KEY)['source']);
        $this->assertNotEmpty($company->get(CompanyProfileService::SETTING_KEY)['researched_at']);
    }

    private function company(): Companies
    {
        return $this->company ??= Companies::factory()->create();
    }

    private function agent(Companies $company): Agent
    {
        return Agent::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => auth()->user()->getId()]);
    }
}
