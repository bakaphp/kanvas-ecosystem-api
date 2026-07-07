<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intellicheck;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Intellicheck\Activities\IdVerificationReportActivity;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadParticipant;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Regression for the co-buyer overwrite bug: a participant verification carries its
 * own peopleId in $params['participant']['peopleId'] and must resolve to that person,
 * never the lead's main buyer. A main-buyer verification omits the participant key and
 * must resolve to the lead's main people.
 */
final class IdVerificationReportParticipantResolutionTest extends TestCase
{
    private function invokeResolve(Lead $lead, array $params): ?People
    {
        $activity = new ReflectionClass(IdVerificationReportActivity::class)->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(IdVerificationReportActivity::class, 'resolveVerifiedPeople');

        return $method->invoke($activity, $lead, $params, app(Apps::class));
    }

    public function testResolvesParticipantVsMainFromPayload(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $coBuyer = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        LeadParticipant::create([
            'leads_id' => $lead->getId(),
            'peoples_id' => $coBuyer->getId(),
            'participants_types_id' => 0,
            'is_deleted' => 0,
        ]);

        $lead->refresh();

        // Participant scan → resolves the participant, never the main buyer.
        $resolved = $this->invokeResolve($lead, [
            'participant' => ['peopleId' => (string) $coBuyer->getId()],
        ]);
        $this->assertSame($coBuyer->getId(), $resolved->getId());
        $this->assertNotSame($lead->people_id, $resolved->getId());

        // Main-buyer scan (no participant key) → resolves the lead's main people.
        $resolved = $this->invokeResolve($lead, [
            'idcheck' => ['data' => ['firstName' => 'Main', 'lastName' => 'Buyer']],
        ]);
        $this->assertSame($lead->people_id, $resolved->getId());

        // Participant id that equals the main people → still the main buyer.
        $resolved = $this->invokeResolve($lead, [
            'participant' => ['peopleId' => (string) $lead->people_id],
        ]);
        $this->assertSame($lead->people_id, $resolved->getId());

        // Unknown/invalid participant id → safe fallback to the main buyer.
        $resolved = $this->invokeResolve($lead, [
            'participant' => ['peopleId' => '999999999'],
        ]);
        $this->assertSame($lead->people_id, $resolved->getId());
        $this->assertNotSame($coBuyer->getId(), $resolved->getId());
    }
}
