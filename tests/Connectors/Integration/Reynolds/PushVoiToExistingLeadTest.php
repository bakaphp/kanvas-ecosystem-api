<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Reynolds;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Reynolds\Actions\PushLeadAction;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Connectors\Reynolds\Exceptions\ReynoldsException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\Connectors\Traits\HasReynoldsConfiguration;
use Tests\TestCase;

/**
 * PM test case #1 — push VOI (Vehicle of Interest) into an existing lead.
 *
 * The Reynolds SalesAssist 1.1 spec set we are integrating against does NOT
 * support this operation. Documenting the limitation here so the constraint
 * is visible from the test suite rather than buried in code comments.
 *
 * Why it does not work:
 *   - Outbound to R&R there are exactly two transactions:
 *       * ISL (Insert Sales Lead): includes <DesiredVehicle> but only fires
 *         for a brand-new prospect. Re-sending ISL for a lead that already
 *         has a REYNOLDS_PROSPECT_ID is explicitly rejected by our
 *         PushLeadAction and would be a logical no-op even if it wasn't.
 *       * USL (Update Sales Lead): has four sub-flows defined in the spec
 *         (Activity / Appointment / Note / Consent). There is NO Vehicle
 *         or DesiredVehicle sub-flow. The XSD does not surface a vehicle
 *         section on the Update Sales Lead schema.
 *   - The only workaround inside SalesAssist is a USL Note transaction
 *     carrying free-text like "Customer now interested in 2024 Malibu",
 *     which we do support via AddNoteToLeadAction. That is unstructured
 *     and does not update the prospect's DesiredVehicle on the R&R side.
 *
 * The test is kept (and runs the negative assertion) so a future spec
 * revision that adds Vehicle to USL would surface the gap immediately —
 * if R&R publishes a USL Vehicle sub-flow we would replace the
 * markTestSkipped with the actual assertion and implement the action.
 */
final class PushVoiToExistingLeadTest extends TestCase
{
    use HasReynoldsConfiguration;

    public function testPushingPushLeadActionRefusesLeadsThatAlreadyExistOnReynoldsSide(): void
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

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        // Mark the lead as already-pushed-to-Reynolds. PushLeadAction must
        // refuse to re-submit it — there is no way to update Vehicle on an
        // existing prospect via SalesAssist.
        $lead->set(CustomFieldEnum::PROSPECT_ID->value, '2078595');

        // Even with a vehicle_of_interest set, PushLeadAction must short-
        // circuit before attempting any Reynolds round-trip.
        $lead->set('vehicle_of_interest', [
            'vin' => '1G1ZE5ST8RF111640',
            'year' => '2024',
            'make' => 'Chevrolet',
            'model' => 'Malibu',
            'stock_type' => 'New',
        ]);

        $this->expectException(ReynoldsException::class);
        $this->expectExceptionMessageMatches('/already exists in Reynolds/i');

        new PushLeadAction($lead)->execute();
    }

    public function testUslHasNoVehicleSubFlowAvailableAsAnActionInTheConnector(): void
    {
        $this->markTestSkipped(
            'Per SalesAssist Update Sales Lead Spec v1.1, USL has four sub-flows '
            . '(Activity, Appointment, Note, Consent) and none of them carry vehicle '
            . 'data. There is therefore no UpdateLeadVehicleAction class to test — '
            . 'the absence is intentional. Workaround for the business case is to '
            . 'send a USL Note via AddNoteToLeadAction describing the vehicle '
            . 'interest change, which is unstructured text and does not update the '
            . "prospect's DesiredVehicle on the R&R side. Re-enable this test only "
            . 'after R&R publishes a USL Vehicle sub-flow.'
        );
    }
}
