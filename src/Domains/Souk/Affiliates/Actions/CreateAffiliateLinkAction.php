<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Actions;

use Baka\Support\Str;
use Illuminate\Support\Facades\DB;
use Kanvas\Souk\Affiliates\DataTransferObject\AffiliateLink as AffiliateLinkData;
use Kanvas\Souk\Affiliates\Models\AffiliateLink;

class CreateAffiliateLinkAction
{
    public function __construct(
        protected readonly AffiliateLinkData $data,
    ) {
    }

    public function execute(): AffiliateLink
    {
        return DB::connection('commerce')->transaction(function () {
            $link = new AffiliateLink();
            $link->apps_id = $this->data->app->getId();
            $link->companies_id = $this->data->company->getId();
            $link->affiliates_id = $this->data->affiliate->getId();
            $link->name = $this->data->name;
            $link->description = $this->data->description;
            $link->custom_slug = $this->data->custom_slug;
            $link->short_code = $this->data->short_code ?? Str::random(8);
            $link->destination_url = $this->data->destination_url;
            $link->link_type = $this->data->link_type;
            $link->config = $this->data->config;
            $link->tracking_method = $this->data->tracking_method;
            $link->cookie_duration_days = $this->data->cookie_duration_days;
            $link->attribution_window_days = $this->data->attribution_window_days;
            $link->attribution_model = $this->data->attribution_model->value;
            $link->override_commission = $this->data->override_commission;
            $link->commission_type = $this->data->commission_type?->value;
            $link->commission_rate = $this->data->commission_rate;
            $link->commission_note = $this->data->commission_note;
            $link->is_active = $this->data->is_active;
            $link->is_approved = $this->data->is_approved;
            $link->approval_notes = $this->data->approval_notes;
            $link->configuration = $this->data->configuration;
            $link->saveOrFail();

            return $link;
        });
    }
}
