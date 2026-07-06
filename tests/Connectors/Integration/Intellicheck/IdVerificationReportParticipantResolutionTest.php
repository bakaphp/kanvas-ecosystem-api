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
 * Regression for the co-buyer overwrite bug: a participant's verification payload
 * routed through this lead-scoped activity must resolve to the participant's own
 * People, never the lead's main buyer. resolveVerifiedPeople() is the gate that
 * decides which People (and therefore whether the lead's own DL slots) get written.
 */
final class IdVerificationReportParticipantResolutionTest extends TestCase
{
    private function invokeResolve(Lead $lead, ?array $getDocs): ?People
    {
        $activity = new ReflectionClass(IdVerificationReportActivity::class)->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(IdVerificationReportActivity::class, 'resolveVerifiedPeople');

        return $method->invoke($activity, $lead, $getDocs);
    }

    private function makeLeadWithCoBuyer(): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $lead->people->update([
            'firstname' => 'Main',
            'lastname' => 'Buyer',
            'license_number' => 'MAIN-LIC-1',
        ]);

        $coBuyer = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'firstname' => 'Co',
                'lastname' => 'Signer',
                'license_number' => 'CO-LIC-2',
            ]);

        LeadParticipant::create([
            'leads_id' => $lead->getId(),
            'peoples_id' => $coBuyer->getId(),
            'participants_types_id' => 0,
            'is_deleted' => 0,
        ]);

        return [$lead->refresh(), $coBuyer];
    }

    public function testCoBuyerPayloadResolvesToCoBuyerNotMainByName(): void
    {
        [$lead, $coBuyer] = $this->makeLeadWithCoBuyer();

        $resolved = $this->invokeResolve($lead, [
            'firstname' => 'Co',
            'lastname' => 'Signer',
            'license' => '',
        ]);

        $this->assertSame($coBuyer->getId(), $resolved->getId());
        $this->assertNotSame($lead->people_id, $resolved->getId());
    }

    public function testCoBuyerPayloadResolvesToCoBuyerByLicense(): void
    {
        [$lead, $coBuyer] = $this->makeLeadWithCoBuyer();

        $resolved = $this->invokeResolve($lead, [
            'firstname' => 'Totally',
            'lastname' => 'Different',
            'license' => 'CO-LIC-2',
        ]);

        $this->assertSame($coBuyer->getId(), $resolved->getId());
    }

    public function testMainBuyerPayloadResolvesToMain(): void
    {
        [$lead] = $this->makeLeadWithCoBuyer();

        $resolved = $this->invokeResolve($lead, [
            'firstname' => 'Main',
            'lastname' => 'Buyer',
            'license' => 'MAIN-LIC-1',
        ]);

        $this->assertSame($lead->people_id, $resolved->getId());
    }

    public function testNoIdentifyingDataFallsBackToMain(): void
    {
        [$lead] = $this->makeLeadWithCoBuyer();

        $this->assertSame($lead->people_id, $this->invokeResolve($lead, [])->getId());
        $this->assertSame($lead->people_id, $this->invokeResolve($lead, null)->getId());
    }

    public function testUnmatchedPayloadFallsBackToMainWithoutTouchingCoBuyer(): void
    {
        [$lead, $coBuyer] = $this->makeLeadWithCoBuyer();

        $resolved = $this->invokeResolve($lead, [
            'firstname' => 'Nobody',
            'lastname' => 'Here',
            'license' => 'UNKNOWN-9',
        ]);

        $this->assertSame($lead->people_id, $resolved->getId());
        $this->assertNotSame($coBuyer->getId(), $resolved->getId());
    }
}
