<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Reynolds;

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
 *         for a brand-new prospect. Once a lead already has a
 *         REYNOLDS_PROSPECT_ID, PushLeadAction upserts by delegating to
 *         UpdateLeadAction (USL) — which is the correct behaviour for the
 *         general case but means the DesiredVehicle block never reaches R&R
 *         via that path.
 *       * USL (Update Sales Lead): has four sub-flows defined in the spec
 *         (Activity / Appointment / Note / Consent). There is NO Vehicle
 *         or DesiredVehicle sub-flow. The XSD does not surface a vehicle
 *         section on the Update Sales Lead schema, and UpdateLeadAction
 *         accordingly does not include one in its payload.
 *   - The only workaround inside SalesAssist is a USL Note transaction
 *     carrying free-text like "Customer now interested in 2024 Malibu",
 *     which we do support via AddNoteToLeadAction. That is unstructured
 *     and does not update the prospect's DesiredVehicle on the R&R side.
 *
 * Both tests are skipped by design — the file exists to keep the
 * constraint discoverable from the test suite so a future spec revision
 * that adds a Vehicle sub-flow to USL surfaces the gap immediately.
 */
final class PushVoiToExistingLeadTest extends TestCase
{
    public function testUslDoesNotCarryDesiredVehicleForExistingProspects(): void
    {
        $this->markTestSkipped(
            'Per SalesAssist Update Sales Lead Spec v1.1, USL has no Vehicle '
            . 'sub-flow. PushLeadAction upserts by delegating to UpdateLeadAction '
            . 'when a lead already carries REYNOLDS_PROSPECT_ID, and that USL '
            . 'payload intentionally omits <DesiredVehicle>. Re-enable this test '
            . 'after R&R publishes a USL Vehicle sub-flow — the assertion would '
            . 'become: the built payload must include <DesiredVehicle> when '
            . 'vehicle_of_interest is set on the lead.'
        );
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
