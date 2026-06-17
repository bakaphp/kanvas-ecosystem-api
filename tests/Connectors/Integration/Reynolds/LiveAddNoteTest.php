<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Reynolds;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Reynolds\Actions\AddNoteToLeadAction;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\Connectors\Traits\HasReynoldsConfiguration;
use Tests\TestCase;

/**
 * Opt-in live round-trip against the Reynolds sandbox for the USL Note
 * sub-flow. Skipped by default — see LivePushLeadTest for env var setup.
 *
 * Exercises both branches of AddNoteToLeadAction::ensureProspectId():
 *
 *   - testAddNoteAutoPushesLeadWhenProspectIdMissing — covers the case
 *     where the Kanvas Lead has not been sent to Reynolds yet. The
 *     action should call PushLeadAction internally, capture the new
 *     ProspectId, then submit the USL Note.
 *
 *   - testAddNoteUsesExistingProspectIdWithoutRePushing — covers the
 *     case where the Lead already has REYNOLDS_PROSPECT_ID set. The
 *     action should skip Push and submit only the USL Note.
 *
 * Each run creates a real prospect + one or two notes in the sandbox.
 */
final class LiveAddNoteTest extends TestCase
{
    use HasReynoldsConfiguration;

    protected function setUp(): void
    {
        parent::setUp();

        if (! getenv('REYNOLDS_LIVE_TEST')) {
            $this->markTestSkipped(
                'Live Reynolds sandbox test — set REYNOLDS_LIVE_TEST=1 plus '
                . 'REYNOLDS_TEST_USERNAME/PASSWORD/ENDPOINT/DEALER_NUMBER env '
                . 'vars to run.'
            );
        }
    }

    public function testAddNoteAutoPushesLeadWhenProspectIdMissing(): void
    {
        [$lead, $people] = $this->buildFreshLead('AutoPush');

        $this->assertNull(
            $lead->get(CustomFieldEnum::PROSPECT_ID->value),
            'Test setup invariant: fresh lead must not carry REYNOLDS_PROSPECT_ID.'
        );

        $result = new AddNoteToLeadAction(
            $lead,
            'Live test note — auto-push case. Customer called asking about financing options.'
        )->execute();

        $this->assertSame(
            'Success',
            $result['response']['TransStatus']['Status'] ?? null,
            'Reynolds sandbox should return Status=Success for the USL Note.'
        );
        $this->assertSame(
            '0',
            (string) ($result['response']['TransStatus']['StatusCode'] ?? null),
            'Reynolds sandbox should return StatusCode=0 for the USL Note.'
        );
        $this->assertNotEmpty(
            $result['prospect_id'],
            'AddNoteToLeadAction should expose the ProspectId it pushed or reused.'
        );

        $lead->refresh();
        $this->assertSame(
            (string) $result['prospect_id'],
            (string) $lead->get(CustomFieldEnum::PROSPECT_ID->value),
            'After auto-push, the Lead must carry the new REYNOLDS_PROSPECT_ID.'
        );
    }

    public function testAddNoteUsesExistingProspectIdWithoutRePushing(): void
    {
        [$lead] = $this->buildFreshLead('ReuseProspect');

        // Bootstrap: push first to acquire a real ProspectId.
        $firstResult = new AddNoteToLeadAction(
            $lead,
            'Live test note — initial note (creates prospect in sandbox).'
        )->execute();

        $originalProspectId = $firstResult['prospect_id'];
        $this->assertNotEmpty($originalProspectId);

        $lead->refresh();

        // Second note: should reuse the same ProspectId, no re-push.
        $secondResult = new AddNoteToLeadAction(
            $lead,
            'Live test note — follow-up note (reuses existing prospect).'
        )->execute();

        $this->assertSame(
            'Success',
            $secondResult['response']['TransStatus']['Status'] ?? null
        );
        $this->assertSame(
            $originalProspectId,
            $secondResult['prospect_id'],
            'Follow-up note must target the same ProspectId — AddNoteToLeadAction '
            . 'should NOT call PushLeadAction again when REYNOLDS_PROSPECT_ID is already set.'
        );
    }

    /**
     * @return array{0: Lead, 1: People}
     */
    private function buildFreshLead(string $tag): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->setupReynoldsConfiguration($app, $company);

        $people = People::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();

        $suffix = substr((string) time(), -6);
        $people->firstname = "KanvasLiveTest{$tag}{$suffix}";
        $people->lastname = 'AddNote';
        $people->saveOrFail();

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        $lead->title = "KanvasLiveTest {$tag} {$suffix}";
        $lead->firstname = $people->firstname;
        $lead->lastname = $people->lastname;
        $lead->saveOrFail();

        return [$lead, $people];
    }
}
