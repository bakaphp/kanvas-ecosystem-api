<?php

declare(strict_types=1);

namespace Tests\Guild\Leads;

use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Override;
use Tests\TestCase;

final class CrmServiceLeadDownloadSettingTest extends TestCase
{
    private Companies $company;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->company = auth()->user()->getCurrentCompany();
        $this->company->del(ConfigurationEnum::CRM_DOWNLOAD_SERVICE_LEADS->value);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->company->del(ConfigurationEnum::CRM_DOWNLOAD_SERVICE_LEADS->value);

        parent::tearDown();
    }

    public function testServiceLeadsAreSkippedUntilTheCompanyTurnsTheSettingOn(): void
    {
        $this->assertTrue(ConfigurationEnum::skipsCrmServiceLead($this->company, 'SERVICE'));
        $this->assertTrue(ConfigurationEnum::skipsCrmServiceLead($this->company, 'Service'));
        $this->assertTrue(ConfigurationEnum::skipsCrmServiceLead($this->company, ' service '));
    }

    public function testEveryOtherLeadTypeIsDownloadedWithTheSettingOff(): void
    {
        $this->assertFalse(ConfigurationEnum::skipsCrmServiceLead($this->company, 'INTERNET'));
        $this->assertFalse(ConfigurationEnum::skipsCrmServiceLead($this->company, 'Showroom'));
        $this->assertFalse(ConfigurationEnum::skipsCrmServiceLead($this->company, 'PARTS_ORDER'));
        $this->assertFalse(ConfigurationEnum::skipsCrmServiceLead($this->company, 'Service Referral'));
        $this->assertFalse(ConfigurationEnum::skipsCrmServiceLead($this->company, null));
    }

    public function testACompanyThatTurnsTheSettingOnDownloadsServiceLeads(): void
    {
        $this->company->set(ConfigurationEnum::CRM_DOWNLOAD_SERVICE_LEADS->value, 1);

        $this->assertFalse(ConfigurationEnum::skipsCrmServiceLead($this->company, 'SERVICE'));
    }

    public function testTheSettingSavedAsZeroKeepsServiceLeadsOut(): void
    {
        $this->company->set(ConfigurationEnum::CRM_DOWNLOAD_SERVICE_LEADS->value, 0);

        $this->assertTrue(ConfigurationEnum::skipsCrmServiceLead($this->company, 'SERVICE'));
    }
}
