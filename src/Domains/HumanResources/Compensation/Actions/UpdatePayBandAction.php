<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Compensation\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\HumanResources\Compensation\DataTransferObject\PayBand as PayBandData;
use Kanvas\HumanResources\Compensation\Models\PayBand;

class UpdatePayBandAction
{
    public function __construct(
        protected readonly PayBand $band,
        protected readonly PayBandData $data,
    ) {
    }

    public function execute(): PayBand
    {
        return DB::connection('hr')->transaction(function () {
            $this->band->position_id = $this->data->position?->getId();
            $this->band->name = $this->data->name;
            $this->band->level = $this->data->level;
            $this->band->currency = $this->data->currency;
            $this->band->pay_frequency = $this->data->payFrequency;
            $this->band->min_amount = $this->data->minAmount;
            $this->band->mid_amount = $this->data->midAmount;
            $this->band->max_amount = $this->data->maxAmount;
            $this->band->effective_from = $this->data->effectiveFrom;
            $this->band->saveOrFail();

            return $this->band;
        });
    }
}
