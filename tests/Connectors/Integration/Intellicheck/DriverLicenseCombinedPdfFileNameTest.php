<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intellicheck;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Services\DriverLicenseCombinedPdfService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Tests\TestCase;

final class DriverLicenseCombinedPdfFileNameTest extends TestCase
{
    public function testTheCombinedPdfIsNumberedFromTheLead(): void
    {
        $lead = $this->makeLead();
        $lead->set('file-count', [['name' => 'drivers-license-combined', 'count' => 2]]);

        $this->assertSame('drivers-license-combined-3.pdf', $this->pdfFileName(new Message(), $lead));
        $this->assertSame([['name' => 'drivers-license-combined', 'count' => 3]], $lead->get('file-count'));
    }

    public function testWithoutALeadItKeepsTheUniqueName(): void
    {
        $lead = $this->makeLead();

        $this->assertMatchesRegularExpression('/^drivers_license_.*_[0-9a-f]{13}\.pdf$/', $this->pdfFileName(new Message()));
        $this->assertNull($lead->get('file-count'));
    }

    private function pdfFileName(Message $message, ?Lead $lead = null): string
    {
        return new class ($message, $lead) extends DriverLicenseCombinedPdfService {
            public function name(): string
            {
                return $this->pdfFileName();
            }
        }->name();
    }

    private function makeLead(): Lead
    {
        return Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create();
    }
}
