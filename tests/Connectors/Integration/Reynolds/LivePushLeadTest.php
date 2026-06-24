<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Reynolds;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Reynolds\Actions\PushLeadAction;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\Connectors\Traits\HasReynoldsConfiguration;
use Tests\TestCase;

/**
 * Opt-in live round-trip against the Reynolds sandbox.
 *
 * Skipped by default. To run:
 *   docker exec \
 *     -e REYNOLDS_LIVE_TEST=1 \
 *     -e REYNOLDS_TEST_USERNAME=... \
 *     -e REYNOLDS_TEST_PASSWORD=... \
 *     -e REYNOLDS_TEST_ENDPOINT='https://b2b-test2.reyrey.com/Sync/RCI/SalesAssistCRM/Receive.ashx' \
 *     -e REYNOLDS_TEST_DEALER_NUMBER=... \
 *     php php vendor/bin/phpunit \
 *     tests/Connectors/Integration/Reynolds/LivePushLeadTest.php
 *
 * Each run creates a real prospect in the dealer's R&R test environment.
 * Names are prefixed "KanvasLiveTest" so the dealer can identify and
 * purge them. Stays out of CI because REYNOLDS_LIVE_TEST is never set
 * there.
 */
final class LivePushLeadTest extends TestCase
{
    use HasReynoldsConfiguration;

    protected function setUp(): void
    {
        parent::setUp();

        if (! getenv('REYNOLDS_LIVE_TEST')) {
            $this->markTestSkipped(
                'Live Reynolds sandbox test — set REYNOLDS_LIVE_TEST=1 plus '
                . 'REYNOLDS_TEST_USERNAME/PASSWORD/ENDPOINT/DEALER_NUMBER env '
                . 'vars to run. Will create a real prospect in the R&R test '
                . 'environment for the configured dealer.'
            );
        }
    }

    public function testPushLeadActionRoundTripsAgainstSandboxAndReturnsProspectId(): void
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

        $people->firstname = 'KanvasLiveTest' . substr((string) time(), -6);
        $people->lastname = 'PushLead';
        $people->saveOrFail();

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        $lead->title = 'KanvasLiveTest PushLead ' . substr((string) time(), -6);
        $lead->firstname = $people->firstname;
        $lead->lastname = $people->lastname;
        $lead->saveOrFail();

        $result = new PushLeadAction($lead)->execute();

        $this->assertSame(
            'Success',
            $result['response']['TransStatus']['Status'] ?? null,
            'Reynolds sandbox should return Status=Success for a valid ISL. Response: '
            . json_encode($result['response'] ?? null)
        );
        $this->assertSame(
            '0',
            (string) ($result['response']['TransStatus']['StatusCode'] ?? null),
            'Reynolds sandbox should return StatusCode=0 (SUCCESS) for a valid ISL.'
        );
        $this->assertNotEmpty(
            $result['prospect_id'],
            'PushLeadAction should expose the new ProspectId returned by Reynolds.'
        );

        $lead->refresh();
        $this->assertSame(
            (string) $result['prospect_id'],
            (string) $lead->get(CustomFieldEnum::PROSPECT_ID->value),
            'PushLeadAction should persist the returned ProspectId on the Lead as REYNOLDS_PROSPECT_ID.'
        );

        $people->refresh();
        $this->assertSame(
            (string) $result['prospect_id'],
            (string) $people->get(CustomFieldEnum::NAME_REC_ID->value),
            'PushLeadAction should persist the returned ProspectId on the People as REYNOLDS_NAME_REC_ID.'
        );
    }
}
