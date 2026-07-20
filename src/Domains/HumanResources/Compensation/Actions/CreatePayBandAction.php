<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Compensation\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\HumanResources\Compensation\DataTransferObject\PayBand as PayBandData;
use Kanvas\HumanResources\Compensation\Models\PayBand;

class CreatePayBandAction
{
    public function __construct(
        protected readonly PayBandData $data,
    ) {
    }

    public function execute(): PayBand
    {
        return DB::connection('hr')->transaction(function () {
            $band = new PayBand();
            $band->apps_id = $this->data->app->getId();
            $band->companies_id = $this->data->company->getId();
            $band->users_id = $this->data->user->getId();
            $band->position_id = $this->data->position?->getId();
            $band->name = $this->data->name;
            $band->level = $this->data->level;
            $band->currency = $this->data->currency;
            $band->pay_frequency = $this->data->payFrequency;
            $band->min_amount = $this->data->minAmount;
            $band->mid_amount = $this->data->midAmount;
            $band->max_amount = $this->data->maxAmount;
            $band->effective_from = $this->data->effectiveFrom;
            $band->saveOrFail();

            return $band;
        });
    }
}
