<?php

declare(strict_types=1);

namespace Kanvas\Scribe\TaxCodes\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\TaxCodes\DataTransferObject\TaxCodeData;
use Kanvas\Scribe\TaxCodes\DataTransferObject\TaxRateData;
use Kanvas\Scribe\TaxCodes\Models\TaxCode;
use Kanvas\Scribe\TaxCodes\Models\TaxRate;
use RuntimeException;

/**
 * Creates a TaxCode + initial TaxRate rows in one transaction.
 *
 * Rates can also be added later via a dedicated AddTaxRateAction (deferred). For Cut B the initial set
 * is supplied alongside the code so jurisdictions like "DR ITBIS 18%" come up complete on creation.
 */
class CreateTaxCodeAction
{
    public function __construct(
        public readonly TaxCodeData $data,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): TaxCode
    {
        return DB::connection('accounting')->transaction(function (): TaxCode {
            $this->assertCodeUnique();

            $taxCode = new TaxCode();
            $taxCode->apps_id = $this->data->app->getId();
            $taxCode->companies_id = $this->data->company->getId();
            $taxCode->code = $this->data->code;
            $taxCode->name = $this->data->name;
            $taxCode->jurisdiction = $this->data->jurisdiction;
            $taxCode->is_active = $this->data->is_active;
            $taxCode->source = $this->data->source;
            $taxCode->external_id = $this->data->external_id;
            $taxCode->metadata = $this->data->metadata;
            $taxCode->users_id = $this->user?->getId();
            $taxCode->save();

            if ($this->data->rates !== null) {
                foreach ($this->data->rates as $rateData) {
                    /** @var TaxRateData $rateData */
                    $rate = new TaxRate();
                    $rate->tax_code_id = $taxCode->id;
                    $rate->name = $rateData->name;
                    $rate->rate = $rateData->rate;
                    $rate->tax_account_id = $rateData->tax_account_id;
                    $rate->sort_order = $rateData->sort_order;
                    $rate->effective_from = $rateData->effective_from;
                    $rate->effective_to = $rateData->effective_to;
                    $rate->metadata = $rateData->metadata;
                    $rate->save();
                }
            }

            $taxCode->load('rates');

            return $taxCode;
        });
    }

    private function assertCodeUnique(): void
    {
        $exists = TaxCode::query()
            ->where('apps_id', $this->data->app->getId())
            ->where('companies_id', $this->data->company->getId())
            ->where('code', $this->data->code)
            ->where('is_deleted', false)
            ->exists();

        if ($exists) {
            throw new RuntimeException(
                "Tax code '{$this->data->code}' is already used in this company."
            );
        }
    }
}
