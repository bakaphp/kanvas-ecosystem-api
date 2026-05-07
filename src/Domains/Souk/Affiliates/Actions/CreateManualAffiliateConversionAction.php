<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Souk\Affiliates\DataTransferObject\AffiliateConversion as AffiliateConversionData;
use Kanvas\Souk\Affiliates\Models\AffiliateConversion;

class CreateManualAffiliateConversionAction
{
    public function __construct(
        protected readonly AffiliateConversionData $data,
    ) {
    }

    public function execute(): AffiliateConversion
    {
        return DB::connection('commerce')->transaction(function () {
            $conversion = new AffiliateConversion();
            $conversion->apps_id = $this->data->app->getId();
            $conversion->affiliates_id = $this->data->affiliate->getId();
            $conversion->affiliate_links_id = $this->data->link?->getId();
            $conversion->orders_id = $this->data->order->getId();
            $conversion->attribution_model = $this->data->attribution_model->value;
            $conversion->order_total = $this->data->order_total;
            $conversion->eligible_amount = $this->data->eligible_amount;
            $conversion->commission_type = $this->data->commission_type->value;
            $conversion->commission_rate = $this->data->commission_rate;
            $conversion->commission_amount = $this->data->commission_amount;
            $conversion->status = $this->data->status->value;
            $conversion->notes = $this->data->notes;
            $conversion->converted_at = $this->data->converted_at !== null ? Carbon::parse($this->data->converted_at) : now();
            $conversion->saveOrFail();

            return $conversion;
        });
    }
}
