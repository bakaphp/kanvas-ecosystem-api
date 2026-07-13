<?php

declare(strict_types=1);

namespace Tests\Scribe\Banking;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mercury\Enums\CustomFieldEnum;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Tests\Scribe\ScribeTestCase;

/**
 * Why Mercury calls `getByCustomFieldBuilderTransactionSafe` and not `getByCustomFieldBuilder`.
 *
 * These run inside the open transaction `DatabaseTransactions` holds on the model's connection — which is the
 * only condition under which the plain join loses sight of a just-written custom field. Swap the calls below
 * back to `getByCustomFieldBuilder` and they start flaking.
 */
final class MercuryCustomFieldLookupTest extends ScribeTestCase
{
    public function testItFindsACrmModelWrittenAfterTheTransactionSnapshotWasTaken(): void
    {
        $customer = $this->seedTestOrganization('Initech LLC');
        $customer->set(CustomFieldEnum::CUSTOMER_ID->value, 'mcus-snapshot');

        /** @var Organization|null $found */
        $found = Organization::getByCustomFieldBuilderTransactionSafe(
            CustomFieldEnum::CUSTOMER_ID->value,
            'mcus-snapshot',
            $this->company,
        )
            ->fromApp($this->kanvasApp)
            ->notDeleted()
            ->first();

        $this->assertNotNull($found);
        $this->assertSame($customer->getId(), $found->getId());
    }

    public function testItFindsAnAccountingModelWrittenAfterTheTransactionSnapshotWasTaken(): void
    {
        $invoice = $this->issueTestInvoice($this->seedTestOrganization('Initech LLC'), 1_000.00);
        $invoice->set(CustomFieldEnum::INVOICE_ID->value, 'minv-snapshot');

        /** @var Invoice|null $found */
        $found = Invoice::getByCustomFieldBuilderTransactionSafe(
            CustomFieldEnum::INVOICE_ID->value,
            'minv-snapshot',
            $this->company,
        )
            ->fromApp($this->kanvasApp)
            ->notDeleted()
            ->first();

        $this->assertNotNull($found);
        $this->assertSame($invoice->getId(), $found->getId());
    }

    /**
     * The whole reason the safe variant exists — same data, same instant, two lookups, different answers.
     *
     * The read on `crm` is what makes this deterministic: it pins that session's REPEATABLE READ view. Without
     * it the join may or may not see the field, depending on whether anything had read on `crm` yet — which is
     * exactly the coin-flip that made this a ~1-in-300 CI failure instead of an honest, permanent one.
     */
    public function testTheJoinBuilderCannotSeeAFieldWrittenAfterItsSnapshotButTheSafeOneCan(): void
    {
        // Unique per run: `ecosystem` is not rolled back, so a fixed value accumulates a row every run.
        $mercuryId = 'mcus-race-' . Str::uuid()->toString();
        $customer = $this->seedTestOrganization('Initech LLC');

        // Pins this `crm` session's REPEATABLE READ view. Without it the join may or may not see the field
        // below, depending on whether anything had read on `crm` yet — the coin-flip that made this a
        // ~1-in-300 CI failure rather than an honest, permanent one.
        DB::connection('crm')->table('organizations')->where('id', $customer->getId())->first();

        $customer->set(CustomFieldEnum::CUSTOMER_ID->value, $mercuryId);

        // The field is committed and on disk — `ecosystem` is not transacted, so `set()` commits immediately.
        $this->assertSame(
            1,
            DB::connection('ecosystem')->table('apps_custom_fields')->where('value', $mercuryId)->count()
        );

        $viaJoin = Organization::getByCustomField(
            CustomFieldEnum::CUSTOMER_ID->value,
            $mercuryId,
            $this->company,
        );

        $viaSafe = Organization::getByCustomFieldTransactionSafe(
            CustomFieldEnum::CUSTOMER_ID->value,
            $mercuryId,
            $this->company,
        );

        DB::connection('ecosystem')->table('apps_custom_fields')->where('value', $mercuryId)->delete();

        $this->assertNull(
            $viaJoin,
            'The join reads `ecosystem` through the `crm` session, whose snapshot predates the custom field.'
        );
        $this->assertNotNull($viaSafe, 'The safe builder reads `apps_custom_fields` on its own connection.');
        $this->assertSame($customer->getId(), $viaSafe->getId());
    }

    public function testAnUnknownValueFindsNothing(): void
    {
        $this->assertNull(
            Organization::getByCustomFieldTransactionSafe(
                CustomFieldEnum::CUSTOMER_ID->value,
                'mcus-nobody',
                $this->company,
            )
        );
    }

    /**
     * `apps_custom_fields` rows outlive the test transaction (`ecosystem` isn't in `$connectionsToTransact`),
     * so a stale id really can come back. The custom field says which row; scoping decides if we may read it.
     */
    public function testTenantScopesChainOntoTheSafeBuilderWithoutAmbiguity(): void
    {
        $invoice = $this->issueTestInvoice($this->seedTestOrganization('Initech LLC'), 1_000.00);
        $invoice->set(CustomFieldEnum::INVOICE_ID->value, 'minv-deleted');

        $invoice->is_deleted = 1;
        $invoice->saveQuietly();

        $found = Invoice::getByCustomFieldBuilderTransactionSafe(
            CustomFieldEnum::INVOICE_ID->value,
            'minv-deleted',
            $this->company,
        )
            ->fromApp($this->kanvasApp)
            ->fromCompany($this->company)
            ->notDeleted()
            ->first();

        $this->assertNull($found);

        $this->assertNotNull(
            DB::connection('accounting')->table('invoices')->where('id', $invoice->getId())->first(),
            'The row is still there — scoping refused it, it is not a missing record.'
        );
    }
}
