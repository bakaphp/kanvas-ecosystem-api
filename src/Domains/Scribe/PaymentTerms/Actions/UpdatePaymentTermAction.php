<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PaymentTerms\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\PaymentTerms\DataTransferObject\PaymentTermData;
use Kanvas\Scribe\PaymentTerms\Models\PaymentTerm;
use RuntimeException;

class UpdatePaymentTermAction
{
    public function __construct(
        public readonly PaymentTerm $paymentTerm,
        public readonly PaymentTermData $data,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): PaymentTerm
    {
        return DB::connection('accounting')->transaction(function (): PaymentTerm {
            $term = $this->paymentTerm;

            if ($term->name !== $this->data->name) {
                $this->assertNameUniqueOnRename($term);
            }

            if ($this->data->is_default && ! $term->is_default) {
                $this->clearExistingDefault($term);
            }

            $term->name = $this->data->name;
            $term->net_days = $this->data->net_days;
            $term->discount_days = $this->data->discount_days;
            $term->discount_pct = $this->data->discount_pct;
            $term->is_default = $this->data->is_default;
            $term->metadata = $this->data->metadata ?? $term->metadata;
            $term->save();

            return $term->refresh();
        });
    }

    private function assertNameUniqueOnRename(PaymentTerm $term): void
    {
        $exists = PaymentTerm::query()
            ->where('apps_id', $term->apps_id)
            ->where('companies_id', $term->companies_id)
            ->where('name', $this->data->name)
            ->where('id', '!=', $term->id)
            ->where('is_deleted', false)
            ->exists();

        if ($exists) {
            throw new RuntimeException(
                "Payment term '{$this->data->name}' already exists in this company."
            );
        }
    }

    private function clearExistingDefault(PaymentTerm $term): void
    {
        PaymentTerm::query()
            ->where('apps_id', $term->apps_id)
            ->where('companies_id', $term->companies_id)
            ->where('is_default', true)
            ->where('id', '!=', $term->id)
            ->update(['is_default' => false]);
    }
}
