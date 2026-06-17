<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Reynolds;

use Tests\TestCase;

/**
 * PM test case #3 — add a co-buyer (participant) into an existing lead.
 *
 * Like PushVoiToExistingLeadTest, the Reynolds SalesAssist 1.1 spec set
 * does NOT support this operation. Documented here so the constraint is
 * surfaced in the test suite.
 *
 * Why it does not work:
 *   - The ISL spec defines a customer as either IndividualCustomer XOR
 *     BusinessCustomer. Test case ISL-038 of the R&R-supplied document
 *     ("Both individual and business supplied") states the payload is
 *     either disambiguated or rejected — there is no way to attach a
 *     second customer.
 *   - The USL sub-flows (Activity / Appointment / Note / Consent) carry
 *     no customer/co-buyer field at all.
 *   - The only "participants" the ISL schema exposes are STAFF roles —
 *     SecondarySalesPerson, Manager, BDCUser — not customer co-buyers.
 *   - There is no DSP (Lead Disposition) event for "co-buyer added".
 *
 * The closest workaround within SalesAssist is a USL Note with free
 * text like "Co-buyer: John Doe, 555-1234", which we support via
 * AddNoteToLeadAction. That is unstructured and does not produce a
 * second linked customer record on the R&R side.
 *
 * A proper round-trip co-buyer flow would require a different R&R
 * interface (Customer Update / CU appears in the Web Service Requirements
 * appendix but is not part of the SalesAssist spec set we received).
 */
final class CoBuyerParticipantTest extends TestCase
{
    public function testNoAddCobuyerActionExistsInTheConnector(): void
    {
        $this->assertFalse(
            class_exists('Kanvas\\Connectors\\Reynolds\\Actions\\AddCobuyerToLeadAction'),
            'A Reynolds AddCobuyerToLeadAction class must not exist — the SalesAssist '
            . 'spec set has no co-buyer / second-customer field on either ISL or USL. '
            . 'Adding one would silently fail or send unrelated data to R&R.'
        );

        $this->assertFalse(
            class_exists('Kanvas\\Connectors\\Reynolds\\Actions\\AddParticipantToLeadAction'),
            'Same constraint for participants — SalesAssist only exposes STAFF '
            . 'participants (SecondarySalesPerson / Manager / BDCUser) on ISL, '
            . 'not customer co-buyers. Those staff fields are populated on push '
            . 'time inside PushLeadAction directly.'
        );
    }

    public function testWorkaroundDocumented(): void
    {
        $this->markTestSkipped(
            'Per SalesAssist Insert Sales Lead Spec v1.1 (test case ISL-038) and '
            . 'Update Sales Lead Spec v1.1, there is no co-buyer or second-customer '
            . 'field. The available workaround is a USL Note via AddNoteToLeadAction '
            . 'with free text describing the co-buyer; this is unstructured and does '
            . 'not produce a linked customer record on the R&R side. Re-enable this '
            . 'test only after either: (a) R&R extends SalesAssist with a co-buyer '
            . 'field, or (b) we layer in the broader RCI Customer Update (CU) '
            . 'interface, which is hinted at in the Web Service Requirements '
            . 'appendix but is a separate spec we do not yet have.'
        );
    }
}
