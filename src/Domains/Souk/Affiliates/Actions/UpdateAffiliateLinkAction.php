<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Souk\Affiliates\DataTransferObject\AffiliateLink as AffiliateLinkData;
use Kanvas\Souk\Affiliates\Models\AffiliateLink;

class UpdateAffiliateLinkAction
{
    public function __construct(
        protected readonly AffiliateLink $link,
        protected readonly AffiliateLinkData $data,
    ) {
    }

    public function execute(): AffiliateLink
    {
        return DB::connection('commerce')->transaction(function () {
            $this->link->name = $this->data->name;
            $this->link->description = $this->data->description;
            $this->link->custom_slug = $this->data->custom_slug;
            $this->link->short_code = $this->data->short_code ?? $this->link->short_code;
            $this->link->destination_url = $this->data->destination_url;
            $this->link->link_type = $this->data->link_type;
            $this->link->config = $this->data->config;
            $this->link->tracking_method = $this->data->tracking_method;
            $this->link->cookie_duration_days = $this->data->cookie_duration_days;
            $this->link->attribution_window_days = $this->data->attribution_window_days;
            $this->link->attribution_model = $this->data->attribution_model->value;
            $this->link->override_commission = $this->data->override_commission;
            $this->link->commission_type = $this->data->commission_type?->value;
            $this->link->commission_rate = $this->data->commission_rate;
            $this->link->commission_note = $this->data->commission_note;
            $this->link->is_active = $this->data->is_active;
            $this->link->is_approved = $this->data->is_approved;
            $this->link->approval_notes = $this->data->approval_notes;
            $this->link->configuration = $this->data->configuration;
            $this->link->saveOrFail();

            return $this->link;
        });
    }
}
