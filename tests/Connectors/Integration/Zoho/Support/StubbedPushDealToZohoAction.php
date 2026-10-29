<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho\Support;

use Kanvas\Connectors\Zoho\Actions\PushDealToZohoAction;
use Kanvas\Connectors\Zoho\Actions\PushFilesToZohoRecordAction;
use Kanvas\Guild\Deals\Models\Deal;
use Webleit\ZohoCrmApi\Modules\Records as ZohoRecordsModule;

/**
 * The real action with its two network seams replaced: the Zoho module and the file pusher.
 */
final class StubbedPushDealToZohoAction extends PushDealToZohoAction
{
    public function __construct(
        Deal $deal,
        private readonly ZohoRecordsModule $module,
        private readonly ?PushFilesToZohoRecordAction $pusher = null
    ) {
        parent::__construct($deal);
    }

    protected function resolveDealModule(Deal $deal): ZohoRecordsModule
    {
        return $this->module;
    }

    protected function filePusher(ZohoRecordsModule $dealModule, string $zohoDealId): PushFilesToZohoRecordAction
    {
        return $this->pusher ?? parent::filePusher($dealModule, $zohoDealId);
    }
}
