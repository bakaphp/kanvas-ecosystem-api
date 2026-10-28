<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intellicheck;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Intellicheck\Actions\VerifyPeopleIdAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

final class VerifyPeopleIdActionReportFileNameTest extends TestCase
{
    public function testTheFirstReportOfALeadIsNumberedOne(): void
    {
        $lead = $this->makeLead();

        $this->assertSame('id-verification-report-1.pdf', $this->reserve($lead->people, $lead));
        $this->assertSame([['name' => 'id-verification-report', 'count' => 1]], $lead->get('file-count'));
    }

    public function testTheCounterAdvancesAndLeavesOtherBasesUntouched(): void
    {
        $lead = $this->makeLead();
        $lead->set('file-count', [
            ['name' => 'upload', 'count' => 4],
            ['name' => 'id-verification', 'count' => 2],
        ]);

        $this->reserve($lead->people, $lead);

        $this->assertSame('id-verification-report-2.pdf', $this->reserve($lead->people, $lead));
        $this->assertSame([
            ['name' => 'upload', 'count' => 4],
            ['name' => 'id-verification', 'count' => 2],
            ['name' => 'id-verification-report', 'count' => 2],
        ], $lead->get('file-count'));
    }

    public function testAnExistingReportEntryIsIncrementedInPlace(): void
    {
        $lead = $this->makeLead();
        $lead->set('file-count', [
            ['name' => 'id-verification-report', 'count' => 6],
            ['name' => 'upload', 'count' => 1],
        ]);

        $this->assertSame('id-verification-report-7.pdf', $this->reserve($lead->people, $lead));
        $this->assertSame([
            ['name' => 'id-verification-report', 'count' => 7],
            ['name' => 'upload', 'count' => 1],
        ], $lead->get('file-count'));
    }

    public function testACoBuyerReportCountsOnTheLead(): void
    {
        $lead = $this->makeLead();
        $coBuyer = People::factory()
            ->withAppId($lead->apps_id)
            ->withCompanyId($lead->companies_id)
            ->withUserId(auth()->user()->getId())
            ->create();

        $this->reserve($lead->people, $lead);

        $this->assertSame('id-verification-report-2.pdf', $this->reserve($coBuyer, $lead));
    }

    private function reserve(People $people, Lead $lead): string
    {
        return new class ($people, $lead) extends VerifyPeopleIdAction {
            public function reserve(): string
            {
                return $this->reserveReportFileName();
            }
        }->reserve();
    }

    private function makeLead(): Lead
    {
        $user = auth()->user();

        return Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create();
    }
}
