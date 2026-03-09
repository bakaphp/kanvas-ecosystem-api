<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Souk\Affiliates\DataTransferObject\AffiliateCommissionPayout as AffiliateCommissionPayoutData;
use Kanvas\Souk\Affiliates\Models\AffiliateCommissionPayout;

class CreateAffiliateCommissionPayoutAction
{
    public function __construct(
        protected readonly AffiliateCommissionPayoutData $data,
    ) {
    }

    public function execute(): AffiliateCommissionPayout
    {
        return DB::connection('commerce')->transaction(function () {
            $payout = new AffiliateCommissionPayout();
            $payout->apps_id = $this->data->app->getId();
            $payout->companies_id = $this->data->company->getId();
            $payout->affiliates_id = $this->data->affiliate->getId();
            $payout->period_start = Carbon::parse($this->data->period_start);
            $payout->period_end = Carbon::parse($this->data->period_end);
            $payout->total_conversions = $this->data->total_conversions;
            $payout->total_order_value = $this->data->total_order_value;
            $payout->gross_commission = $this->data->gross_commission;
            $payout->fees = $this->data->fees;
            $payout->adjustments = $this->data->adjustments;
            $payout->net_commission = $this->data->net_commission;
            $payout->payout_status = $this->data->payout_status->value;
            $payout->payout_method = $this->data->payout_method?->value;
            $payout->approval_notes = $this->data->approval_notes;
            $payout->configuration = $this->data->configuration;
            $payout->saveOrFail();

            return $payout;
        });
    }
}
