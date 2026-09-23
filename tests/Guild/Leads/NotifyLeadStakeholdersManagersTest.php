<?php

declare(strict_types=1);

namespace Tests\Guild\Leads;

use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Services\CompanyManagerService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\NotifyLeadStakeholdersService;
use Kanvas\Notifications\Templates\Blank;
use Tests\TestCase;

final class NotifyLeadStakeholdersManagersTest extends TestCase
{
    public function testACompanyWithNoManagersNotifiesNobodyAndLeavesTheLeadUnflagged(): void
    {
        // Its own company: the cached test user's company may hold the Managers role from another test.
        $lead = $this->makeLead(Companies::factory()->create());
        Notification::fake();

        new NotifyLeadStakeholdersService($lead, $this->notification($lead))->managers();

        Notification::assertNothingSent();
        $this->assertNull($lead->get('sent_email_notification_to_manager'));
    }

    public function testTheLegacyCompanyManagerListIsStillNotified(): void
    {
        $lead = $this->makeLead();
        $lead->company->set(CompanyManagerService::LEGACY_MANAGERS_SETTING, [auth()->user()->getId()]);
        Notification::fake();

        new NotifyLeadStakeholdersService($lead, $this->notification($lead))->managers();

        Notification::assertSentTo(auth()->user(), Blank::class);
        $this->assertEquals(1, $lead->get('sent_email_notification_to_manager'));
    }

    public function testALeadAlreadyFlaggedIsNotNotifiedTwice(): void
    {
        $lead = $this->makeLead();
        $lead->company->set(CompanyManagerService::LEGACY_MANAGERS_SETTING, [auth()->user()->getId()]);
        $lead->set('sent_email_notification_to_manager', 1);
        Notification::fake();

        new NotifyLeadStakeholdersService($lead, $this->notification($lead))->managers();

        Notification::assertNothingSent();
    }

    private function notification(Lead $lead): Blank
    {
        return new Blank(
            'lead-manager-notification',
            [],
            ['mail'],
            $lead
        );
    }

    private function makeLead(?Companies $company = null): Lead
    {
        $company ??= auth()->user()->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId())
            ->create();

        $lead->company->set(CompanyManagerService::LEGACY_MANAGERS_SETTING, []);

        return $lead;
    }
}
