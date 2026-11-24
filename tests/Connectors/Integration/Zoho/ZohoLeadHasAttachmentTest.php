<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Connectors\Zoho\DataTransferObject\ZohoLead;
use Tests\Connectors\Integration\Zoho\Concerns\BuildsZohoDeal;
use Tests\TestCase;

final class ZohoLeadHasAttachmentTest extends TestCase
{
    use BuildsZohoDeal;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social', 'ecosystem'];

    public function testAFileOnTheLeadFlagsHasAttachment(): void
    {
        $lead = $this->makeLead($this->makeZohoCompany());
        $lead->addFileFromUrl('https://example.com/zoho/application.pdf', 'application.pdf');

        $this->assertTrue(ZohoLead::fromLead($lead)->toArray()[ZohoLead::HAS_ATTACHMENT_FIELD]);
    }

    public function testTheReceiverFormsAttachmentUrlsFlagHasAttachment(): void
    {
        $lead = $this->makeLead($this->makeZohoCompany());
        $lead->set('Attachments', 'https://example.com/a.pdf, https://example.com/b.pdf');

        $this->assertTrue(ZohoLead::fromLead($lead)->toArray()[ZohoLead::HAS_ATTACHMENT_FIELD]);
    }

    public function testALeadWithoutAttachmentsSendsNothingForTheFlag(): void
    {
        $lead = $this->makeLead($this->makeZohoCompany());

        $this->assertArrayNotHasKey(ZohoLead::HAS_ATTACHMENT_FIELD, ZohoLead::fromLead($lead)->toArray());
    }
}
