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

/** Voids a previously-pushed AP bill in Acumatica by reversing it: ReverseBill -> release -> apply via the Check entity -> ReleaseCheck. */
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
                // Resume a prior attempt's leftover Debit Adj. instead of calling ReverseBill again — a
                // failure partway through this method (e.g. a session hiccup on the release/apply steps)
                // still leaves ReverseBill's own effect committed, so retrying blind piles up duplicates.
                $existing = $this->debitAdjustmentsByVendorRef($client, $vendorCode, $vendorRef);

                if ($existing !== []) {
                    // array_key_first() on a purely-numeric ReferenceNbr (the common case) comes back as
                    // an int — PHP casts numeric string array keys automatically — so re-cast to string.
                    $debitAdjRef = (string) array_key_first($existing);
                    $debitAdjId = $existing[$debitAdjRef];
                } else {
                    // A client timeout here doesn't mean it failed server-side; the poll below is the real check.
                    try {
                        $client->invokeAction('Bill', 'ReverseBill', ['entity' => ['id' => $billGuid]]);
                    } catch (Throwable) {
                    }

                    [$debitAdjRef, $debitAdjId] = $this->findNewDebitAdjustment($client, $vendorCode, $vendorRef, []);
                }

                $this->releaseAndApply($client, $debitAdjId, $debitAdjRef, $billRef, $amount);

                return $debitAdjRef;
            }
        );
    }

    /** Advances a Debit Adj. through Hold->Release->apply-to-bill->ReleaseCheck, skipping any step it's already past. */
    private function releaseAndApply(Client $client, string $debitAdjId, string $debitAdjRef, string $billRef, float $amount): void
    {
        $status = AcumaticaPayload::value($client->get('Bill/' . $debitAdjId), 'Status');

        if ($status === 'Closed') {
            return;
        }

        if ($status === 'On Hold') {
            $client->put('Bill', ['id' => $debitAdjId] + AcumaticaPayload::wrap(['Hold' => false]));

            try {
                $client->invokeAction('Bill', 'ReleaseBill', ['entity' => ['id' => $debitAdjId]]);
            } catch (Throwable) {
            }

            $this->waitForBillStatus($client, $debitAdjId, ['Open', 'Balanced']);
        }

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
                $ref = (string) array_key_first($new);

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

    /** Confirms ReleaseCheck actually committed the application (Status = Closed). */
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
