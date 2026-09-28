<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intellicheck;

use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Intellicheck\Actions\VerifyPeopleIdAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Templates\Blank;
use Tests\TestCase;

/**
 * Both Intellicheck activities run through this action, so their shared report rules are pinned here.
 */
final class VerifyPeopleIdActionReportTest extends TestCase
{
    public function testACoBuyerKeepsItsOwnResultAndTheLeadIsUntouched(): void
    {
        $lead = $this->makeLead();
        $coBuyer = $this->makePerson($lead);

        new VerifyPeopleIdAction($coBuyer, $lead)->execute($this->verificationData(), sendNotification: false);

        $this->assertIsArray($coBuyer->get('id_verification'));
        $this->assertNull($lead->get('id_verification'), "a co-buyer's result must not describe the lead");
    }

    public function testTheMainBuyerResultLandsOnTheLeadAndThePerson(): void
    {
        $lead = $this->makeLead();

        new VerifyPeopleIdAction($lead->people, $lead)->execute($this->verificationData(), sendNotification: false);

        $this->assertIsArray($lead->people->get('id_verification'));
        $this->assertIsArray($lead->get('id_verification'));
    }

    public function testTheCompanyOptOutSuppressesTheEmail(): void
    {
        $lead = $this->makeLead();
        $lead->company->set('disable_id_verification_email', true);
        Notification::fake();

        new VerifyPeopleIdAction($lead->people, $lead)->execute($this->verificationData());

        Notification::assertNothingSent();
    }

    public function testACompanyManagerWhoAlsoOwnsTheLeadIsEmailedOnce(): void
    {
        $lead = $this->makeLead();
        $lead->company->set('company_manager', [auth()->user()->getId()]);
        Notification::fake();

        new VerifyPeopleIdAction($lead->people, $lead)->execute($this->verificationData());

        Notification::assertSentToTimes(auth()->user(), Blank::class, 1);
    }

    private function verificationData(): array
    {
        return ['idcheck' => ['data' => ['firstName' => 'Keira', 'lastName' => 'Knightley']]];
    }

    private function makeLead(): Lead
    {
        $user = auth()->user();

        $lead = Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create();

        $lead->leads_owner_id = $user->getId();
        $lead->users_id = $user->getId();
        $lead->saveQuietly();
        $lead->refresh();

        $lead->company->set('company_manager', []);
        $lead->company->set('disable_id_verification_email', false);

        return $lead;
    }

    private function makePerson(Lead $lead): People
    {
        return People::factory()
            ->withAppId($lead->apps_id)
            ->withCompanyId($lead->companies_id)
            ->withUserId(auth()->user()->getId())
            ->create([
                'firstname' => 'Co',
                'lastname' => 'Buyer',
            ]);
    }
}
