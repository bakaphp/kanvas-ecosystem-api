<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Neuron\Tools\CRM;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadSource;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\LeadIntentTool;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class LeadIntentToolTest extends TestCase
{
    use DatabaseTransactions;

    protected Apps $appModel;
    protected Companies $company;
    protected Users $user;
    protected Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->appModel = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();

        $leadType = LeadType::create([
            'apps_id' => $this->appModel->getId(),
            'companies_id' => $this->company->getId(),
            'name' => 'Contact Us',
            'description' => 'Test Description',
            'is_active' => 1,
        ]);
        $leadSource = LeadSource::create([
            'apps_id' => $this->appModel->getId(),
            'companies_id' => $this->company->getId(),
            'name' => 'Contact Us',
            'description' => 'Test Description',
            'is_active' => 1,
            'leads_types_id' => $leadType->id,
        ]);

        $this->company->set('adf_sources', [
            [
                'Source' => 'Contact Us',
                'Sub_Source' => 'Website',
                'Backend' => 'General Inquiry',
                'Default_Completion_Status' => 'New',
            ],
            [
                'Source' => 'Contact Us',
                'Sub_Source' => 'Phone',
                'Backend' => 'Phone Inquiry',
                'Default_Completion_Status' => 'Contacted',
            ],
        ]);

        $this->lead = Lead::factory()
            ->withAppId($this->appModel->getId())
            ->withCompanyId($this->company->getId())
            ->create([
                'leads_types_id' => $leadType->id,
                'leads_sources_id' => $leadSource->id,
            ]);
        $this->lead->set('sub_source', 'Phone');
    }

    public function testMatchesSubSourceWhenIgnoreSubSourceIsNotConfigured(): void
    {
        $intent = $this->tool()(lead_id: $this->lead->getId());

        $this->assertEquals('Phone Inquiry', $intent['lead_intent']);
        $this->assertEquals('Contacted', $intent['intent_completion_status']);
    }

    public function testMatchesSubSourceWhenIgnoreSubSourceIsFalse(): void
    {
        $this->company->set(ConfigurationEnum::IGNORE_SUB_SOURCE->value, false);

        $intent = $this->tool()(lead_id: $this->lead->getId());

        $this->assertEquals('Phone Inquiry', $intent['lead_intent']);
        $this->assertEquals('Contacted', $intent['intent_completion_status']);
    }

    public function testMatchesSourceOnlyWhenIgnoreSubSourceIsTrue(): void
    {
        $this->company->set(ConfigurationEnum::IGNORE_SUB_SOURCE->value, true);

        $intent = $this->tool()(lead_id: $this->lead->getId());

        $this->assertEquals('General Inquiry', $intent['lead_intent']);
        $this->assertEquals('New', $intent['intent_completion_status']);
    }

    private function tool(): LeadIntentTool
    {
        return new LeadIntentTool()->withContext($this->appModel, $this->company, $this->user);
    }
}
