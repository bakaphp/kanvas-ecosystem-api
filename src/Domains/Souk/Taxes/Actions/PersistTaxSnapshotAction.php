<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Taxes\DataTransferObject\TaxComponent;
use Kanvas\Souk\Taxes\DataTransferObject\TaxContext;
use Kanvas\Souk\Taxes\DataTransferObject\TaxResult;
use Kanvas\Souk\Taxes\Models\OrderTaxComponent;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Kanvas\Souk\Taxes\Enums\TaxStatusEnum;

class PersistTaxSnapshotAction
{
    public function __construct(
        private readonly TaxContext $context,
        private readonly TaxResult $result,
        private readonly Apps $app,
        private readonly string $provider,
        private readonly ?int $orderId = null,
        private readonly ?string $cartSessionId = null,
    ) {
    }

    public function execute(): OrderTaxSnapshot
    {
        return DB::connection('commerce')->transaction(function () {
            $snapshot = $this->createSnapshot();
            $this->createComponents($snapshot);

            return $snapshot;
        });
    }

    private function createSnapshot(): OrderTaxSnapshot
    {
        $snapshot = new OrderTaxSnapshot();
        $snapshot->apps_id = $this->app->getId();
        $snapshot->order_id = $this->orderId;
        $snapshot->cart_session_id = $this->cartSessionId ?? $this->context->cartSessionId;
        $snapshot->kind = 'calculation';
        $snapshot->treatment = $this->result->treatment->value;
        $snapshot->status = TaxStatusEnum::CALCULATED->value;
        $snapshot->provider = $this->provider;
        $snapshot->input_hash = $this->context->inputHash();
        $snapshot->tax_amount = $this->result->totalTax;
        $snapshot->taxable_amount = $this->result->totalTaxable;
        $snapshot->remaining_reversible_amount = $this->result->totalTaxable;
        $snapshot->remaining_reversible_tax = $this->result->totalTax;
        $snapshot->provider_transaction_id = $this->result->providerTransactionId;
        $snapshot->souk_tax_engine_enabled = true;
        $snapshot->saveOrFail();

        return $snapshot;
    }

    private function createComponents(OrderTaxSnapshot $snapshot): void
    {
        foreach ($this->result->components as $component) {
            $this->createComponent($snapshot, $component);
        }
    }

    private function createComponent(OrderTaxSnapshot $snapshot, TaxComponent $component): void
    {
        $record = new OrderTaxComponent();
        $record->snapshot_id = $snapshot->id;
        $record->line_id = $component->lineId;
        $record->tax_type = $component->taxType;
        $record->jurisdiction = $component->jurisdiction;
        $record->rate = $component->rate;
        $record->taxable_amount = $component->taxableAmount;
        $record->tax_amount = $component->taxAmount;
        $record->taxability_reason = $component->taxabilityReason;
        $record->sourcing = $component->sourcing;
        $record->saveOrFail();
    }
}
