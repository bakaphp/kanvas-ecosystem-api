<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PaymentTerms\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\PaymentTerms\DataTransferObject\PaymentTerm as PaymentTermData;
use Kanvas\Scribe\PaymentTerms\Models\PaymentTerm;
use RuntimeException;

class CreatePaymentTermAction
{
    public function __construct(
        public readonly PaymentTermData $data,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): PaymentTerm
    {
        return DB::connection('accounting')->transaction(function (): PaymentTerm {
            $this->assertNameUnique();

            if ($this->data->is_default) {
                $this->clearExistingDefault();
            }

            $term = new PaymentTerm();
            $term->apps_id = $this->data->app->getId();
            $term->companies_id = $this->data->company->getId();
            $term->name = $this->data->name;
            $term->net_days = $this->data->net_days;
            $term->discount_days = $this->data->discount_days;
            $term->discount_pct = $this->data->discount_pct;
            $term->is_default = $this->data->is_default;
            $term->metadata = $this->data->metadata;
            $term->users_id = $this->user?->getId();
            $term->save();

            return $term->refresh();
        });
    }

    private function assertNameUnique(): void
    {
        $exists = PaymentTerm::query()
            ->where('apps_id', $this->data->app->getId())
            ->where('companies_id', $this->data->company->getId())
            ->where('name', $this->data->name)
            ->where('is_deleted', false)
            ->exists();

        if ($exists) {
            throw new RuntimeException(
                "Payment term '{$this->data->name}' already exists in this company."
            );
        }
    }

    private function clearExistingDefault(): void
    {
        PaymentTerm::query()
            ->where('apps_id', $this->data->app->getId())
            ->where('companies_id', $this->data->company->getId())
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
