<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Bills\Actions\Concerns\PersistsBillLines;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Exceptions\InvalidBillTransitionException;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Bills\Models\BillLine;
use Kanvas\Scribe\Bills\Models\BillTaxLine;
use Kanvas\Workflow\Enums\WorkflowEnum;

class UpdateBillAction
{
    use PersistsBillLines;

    public function __construct(
        public readonly Bill $bill,
        public readonly BillData $data,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): Bill
    {
        if ($this->bill->document_status !== BillDocumentStatusEnum::DRAFT) {
            throw new InvalidBillTransitionException(
                "Bill {$this->bill->id} cannot be updated — status is "
                . "'{$this->bill->document_status->value}'. Only draft bills are editable. "
                . 'Void + re-create for post-receive changes.'
            );
        }

        $bill = DB::connection('accounting')->transaction(function (): Bill {
            $bill = $this->bill;
            [$totals, $baseTotals] = $this->computeTotals($this->data);
            $fxRate = $this->data->fx_rate_to_base;

            $bill->bill_number = $this->data->bill_number;
            $bill->tax_calculation_mode = $this->data->tax_calculation_mode;
            $bill->net_terms_days = $this->data->net_terms_days ?? 0;
            $bill->bill_date = $this->data->bill_date;
            $bill->received_date = $this->data->received_date;
            $bill->due_date = $this->data->due_date;
            $bill->scheduled_payment_date = $this->data->scheduled_payment_date;
            $bill->currency = $this->data->currency;
            $bill->fx_rate_to_base = $fxRate;
            $bill->fx_rate_at = $this->data->bill_date ?? Carbon::now();
            $bill->subtotal_native = $totals['subtotal'];
            $bill->tax_native = $totals['tax'];
            $bill->discount_native = $totals['discount'];
            $bill->total_native = $totals['total'];
            $bill->balance_due_native = $totals['total'];
            $bill->subtotal_base = $baseTotals['subtotal'];
            $bill->tax_base = $baseTotals['tax'];
            $bill->discount_base = $baseTotals['discount'];
            $bill->total_base = $baseTotals['total'];
            $bill->balance_due_base = $baseTotals['total'];
            $bill->tax_metadata = $this->data->tax_metadata;
            $bill->regional_compliance = $this->data->regional_compliance;
            $bill->notes = $this->data->notes;
            $bill->internal_notes = $this->data->internal_notes;
            $bill->terms = $this->data->terms;
            $bill->purchase_order_id = $this->data->purchase_order_id;
            $bill->metadata = $this->data->metadata;

            // Vendor reference — allow swap (or clear) while in draft. This is the primary motivator
            // for this Action: PDF-ingested drafts land without a resolved vendor; operators need to
            // attach one before Receive. Don't update display_name/legal_name/tax_id/email — those
            // freeze at Receive time from the Guild Org snapshot.
            if ($this->data->vendor !== null) {
                $bill->vendor_organization_id = $this->data->vendor->getPayeeId();
            } else {
                $bill->vendor_organization_id = null;
            }

            $bill->vendor_display_name = $this->data->vendor_display_name;
            $bill->vendor_legal_name = $this->data->vendor_legal_name;
            $bill->vendor_tax_id = $this->data->vendor_tax_id;
            $bill->vendor_email = $this->data->vendor_email;
            $bill->vendor_address_snapshot = $this->data->vendor_address_snapshot;

            $bill->save();

            // Lines + tax lines — replace wholesale
            BillLine::query()->where('bill_id', $bill->id)->delete();
            BillTaxLine::query()->where('bill_id', $bill->id)->delete();

            $this->persistLines($bill, $this->data);
            $this->persistTaxLines($bill, $this->data);

            $bill->load(['lines', 'taxLines']);

            return $bill;
        });

        $bill->fireWorkflowAfterCommit(
            WorkflowEnum::UPDATED->value,
            [
                'app' => $bill->app,
                'entity' => 'bill',
            ]
        );

        return $bill;
    }
}
