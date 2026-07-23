<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Acumatica\Client;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Connectors\Acumatica\Services\AcumaticaWriteService;
use Kanvas\Connectors\Acumatica\Support\AcumaticaPayload;
use Kanvas\Connectors\Acumatica\Traits\HasAcumaticaWriter;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Bills\Models\Bill;
use Throwable;

/**
 * Voids a previously-pushed AP bill in Acumatica — the API equivalent of the manual UI flow (Actions ->
 * Reverse -> release the Debit Adj. -> APPLY into Checks and Payments -> Load Documents -> Release).
 *
 * Reverse-engineered against a live staging tenant: there is no single "void" action, so this drives
 * the same five steps Acumatica's own screens perform, across one authenticated session:
 *   1. `Bill/ReverseBill` on the original bill's id — creates a new Debit Adj. (Type='Debit Adj.')
 *      document against the same vendor. The action itself returns no entity data, so the new
 *      document is identified by diffing the vendor's Debit Adj. set before/after (see
 *      findNewDebitAdjustment()).
 *   2. Clear the Debit Adj.'s Hold flag (it's created On Hold) and release it via `Bill/ReleaseBill` —
 *      both against the SAME `Bill` entity the original bill lives on, since AP301000 (Bills and
 *      Adjustments) treats Bill/Debit Adj./Credit Adj. as one entity distinguished only by Type.
 *   3. Apply it against the original bill via the `Check` entity — NOT `Payment` (that entity is
 *      AR-only: its schema has no `Vendor` field at all, confirmed against this tenant's swagger; the
 *      AP-side equivalent, matching AP302000 Checks and Payments, is `Check`). Critically, `Check` must
 *      be addressed by its NATURAL KEY (Type + ReferenceNbr) here, not by `id` — the same GUID that
 *      works for step 2's `Bill`-entity calls returns "No entity satisfies the condition" when used
 *      against `Check`; these are distinct contract entities over the same underlying document.
 *   4. `Check/ReleaseCheck` on the same id — commits the application, closing both documents.
 *
 * Releases in this tenant come back as HTTP 202 (async), so each release is followed by a short poll
 * rather than trusted to have taken effect immediately.
 */
class VoidApBillAction
{
    use HasAcumaticaWriter;

    private const MAX_POLL_ATTEMPTS = 8;
    private const POLL_DELAY_SECONDS = 4;

    /** The bill's own app — the tenant whose Acumatica config/credentials this void runs against. */
    protected Apps $app;

    public function __construct(
        protected Bill $bill,
        ?AcumaticaWriteService $writer = null,
    ) {
        $this->app = $bill->app;
        $this->writer = $writer;
    }

    /**
     * @return string the Acumatica ReferenceNbr of the Debit Adjustment that voided the bill
     */
    public function execute(): string
    {
        $billRef = (string) $this->bill->get(CustomFieldEnum::BILL_REF->value, '');
        $billGuid = (string) $this->bill->get(CustomFieldEnum::BILL_ID->value, '');

        if ($billRef === '' || $billGuid === '') {
            throw new AcumaticaWriteException(
                "Bill {$this->bill->getId()} has no Acumatica reference — it must be pushed before it can be voided."
            );
        }

        $vendorCode = $this->vendorCode();

        if ($vendorCode === '') {
            throw new AcumaticaWriteException(
                "Bill {$this->bill->getId()} has no vendor Acumatica code — cannot void it."
            );
        }

        $vendorRef = (string) $this->bill->bill_number;
        $amount = (float) $this->bill->balance_due_native;

        if ($amount <= 0.0) {
            throw new AcumaticaWriteException(
                "Bill {$this->bill->getId()} has no outstanding balance to void."
            );
        }

        return $this->writer()->withSession(
            function (Client $client) use ($billGuid, $billRef, $vendorCode, $vendorRef, $amount): string {
                $before = $this->debitAdjustmentsByVendorRef($client, $vendorCode, $vendorRef);

                // This tenant sometimes takes long enough on ReverseBill that the HTTP client gives up
                // before Acumatica replies, even though the reversal completed server-side (confirmed
                // against a live run: the new Debit Adj. showed up despite a client-side timeout here).
                // The poll below is the real source of truth either way, so a timeout on this call alone
                // isn't fatal — only a poll that never finds a new document is.
                try {
                    $client->invokeAction('Bill', 'ReverseBill', ['entity' => ['id' => $billGuid]]);
                } catch (Throwable) {
                }

                [$debitAdjRef, $debitAdjId] = $this->findNewDebitAdjustment($client, $vendorCode, $vendorRef, $before);

                $client->put('Bill', ['id' => $debitAdjId] + AcumaticaPayload::wrap(['Hold' => false]));

                try {
                    $client->invokeAction('Bill', 'ReleaseBill', ['entity' => ['id' => $debitAdjId]]);
                } catch (Throwable) {
                }

                $this->waitForBillStatus($client, $debitAdjId, ['Open', 'Balanced']);

                $applyBody = AcumaticaPayload::wrap([
                    'Type' => 'Debit Adj.',
                    'ReferenceNbr' => $debitAdjRef,
                ]);
                $applyBody['Details'] = [
                    AcumaticaPayload::wrap([
                        'DocType' => 'Bill',
                        'ReferenceNbr' => $billRef,
                        'AmountPaid' => $amount,
                    ]),
                ];
                $client->put('Check', $applyBody);

                try {
                    $client->invokeAction('Check', 'ReleaseCheck', ['entity' => ['id' => $debitAdjId]]);
                } catch (Throwable) {
                }

                $this->waitForCheckClosed($client, $debitAdjId);

                return $debitAdjRef;
            }
        );
    }

    /**
     * @return array<string, string> ReferenceNbr => id, for every Debit Adj. this vendor has against
     *                                $vendorRef (there may be more than one from earlier attempts).
     */
    private function debitAdjustmentsByVendorRef(Client $client, string $vendorCode, string $vendorRef): array
    {
        $filter = "Vendor eq '" . AcumaticaPayload::escapeLiteral($vendorCode) . "' and Type eq 'Debit Adj.' "
            . "and VendorRef eq '" . AcumaticaPayload::escapeLiteral($vendorRef) . "'";

        $records = $client->get('Bill', ['$filter' => $filter, '$top' => 50]);

        $byRef = [];

        foreach ($records as $record) {
            $ref = AcumaticaPayload::value($record, 'ReferenceNbr');
            $id = AcumaticaPayload::recordId($record);

            if (is_string($ref) && $ref !== '' && $id !== null) {
                $byRef[$ref] = $id;
            }
        }

        return $byRef;
    }

    /**
     * @param array<string, string> $before
     *
     * @return array{0: string, 1: string} the new Debit Adj.'s ReferenceNbr and id
     */
    private function findNewDebitAdjustment(Client $client, string $vendorCode, string $vendorRef, array $before): array
    {
        for ($attempt = 1; $attempt <= self::MAX_POLL_ATTEMPTS; $attempt++) {
            $after = $this->debitAdjustmentsByVendorRef($client, $vendorCode, $vendorRef);
            $new = array_diff_key($after, $before);

            if ($new !== []) {
                $ref = array_key_first($new);

                return [$ref, $new[$ref]];
            }

            sleep(self::POLL_DELAY_SECONDS);
        }

        throw new AcumaticaWriteException(
            "ReverseBill did not produce a new Debit Adj. for vendor {$vendorCode} / {$vendorRef} in time."
        );
    }

    /**
     * @param array<int, string> $acceptableStatuses
     */
    private function waitForBillStatus(Client $client, string $id, array $acceptableStatuses): void
    {
        for ($attempt = 1; $attempt <= self::MAX_POLL_ATTEMPTS; $attempt++) {
            $record = $client->get('Bill/' . $id);
            $status = AcumaticaPayload::value($record, 'Status');

            if (in_array($status, $acceptableStatuses, true)) {
                return;
            }

            sleep(self::POLL_DELAY_SECONDS);
        }

        throw new AcumaticaWriteException("Debit Adj. {$id} did not reach an applicable status in time.");
    }

    /**
     * Confirms ReleaseCheck actually committed the application — a Check that's still Open (not Closed)
     * means the release didn't take, whether or not the invokeAction call itself came back in time.
     */
    private function waitForCheckClosed(Client $client, string $id): void
    {
        for ($attempt = 1; $attempt <= self::MAX_POLL_ATTEMPTS; $attempt++) {
            $record = $client->get('Check/' . $id);
            $status = AcumaticaPayload::value($record, 'Status');

            if ($status === 'Closed') {
                return;
            }

            sleep(self::POLL_DELAY_SECONDS);
        }

        throw new AcumaticaWriteException("Debit Adj. {$id} did not close after ReleaseCheck in time.");
    }

    private function vendorCode(): string
    {
        $vendor = $this->vendorOrg();

        return $vendor !== null ? (string) $vendor->get(CustomFieldEnum::VENDOR_ID->value, '') : '';
    }

    private function vendorOrg(): ?Organization
    {
        if ($this->bill->vendor_organization_id === null) {
            return null;
        }

        return Organization::query()->where('id', $this->bill->vendor_organization_id)->first();
    }
}
