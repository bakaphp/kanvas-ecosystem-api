<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Zoho\Actions\Concerns\TracksZohoSyncedFiles;
use Kanvas\Connectors\Zoho\Actions\Concerns\WritesZohoRecordWithCoercion;
use Kanvas\Connectors\Zoho\DataTransferObject\ZohoDeal;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\Services\ZohoFieldMapService;
use Kanvas\Connectors\Zoho\ZohoService;
use Kanvas\Guild\Deals\Models\Deal;
use Sentry\Laravel\Facade as Sentry;
use Webleit\ZohoCrmApi\Exception\ApiError;
use Webleit\ZohoCrmApi\Modules\Records as ZohoRecordsModule;

class PushDealToZohoAction
{
    use TracksZohoSyncedFiles;
    use WritesZohoRecordWithCoercion;

    public function __construct(
        protected Deal $deal
    ) {
    }

    /**
     * `recordExists` is false when Kanvas holds a Zoho id that Zoho no longer has. Unlike a lead,
     * the deal is not closed over it — the funding team owns that record, so the caller flags it.
     *
     * @return array{zohoDealId: string|null, zohoRequest: array, recordExists: bool, files: array{uploaded: int[], failed: array<int, string>}|null}
     */
    public function execute(): array
    {
        return DB::connection('crm')->transaction(function () {
            /** @var Deal $deal */
            $deal = Deal::where('id', $this->deal->getId())->lockForUpdate()->firstOrFail();
            $zohoData = ZohoDeal::fromDeal($deal)->toArray();
            $dealModule = $this->resolveDealModule($deal);
            $zohoDealId = $deal->get(CustomFieldEnum::ZOHO_DEAL_ID->value);

            if (empty($zohoDealId)) {
                $zohoDealId = $this->createDeal($dealModule, $deal, $zohoData);
                $this->writeRecordLinks(
                    $dealModule,
                    $zohoDealId,
                    ZohoFieldMapService::dealIdLinkFields($deal, $zohoDealId)
                );
            } elseif (empty($dealModule->get((string) $zohoDealId)->getData())) {
                return [
                    'zohoDealId' => (string) $zohoDealId,
                    'zohoRequest' => $zohoData,
                    'recordExists' => false,
                    'files' => null,
                ];
            } else {
                $zohoData = [
                    ...$zohoData,
                    ...ZohoFieldMapService::dealIdLinkFields($deal, (string) $zohoDealId),
                ];
                $this->updateRecord($dealModule, (string) $zohoDealId, $zohoData);
            }

            return [
                'zohoDealId' => $zohoDealId,
                'zohoRequest' => $zohoData,
                'recordExists' => true,
                'files' => $zohoDealId !== null ? $this->uploadAttachments($dealModule, $deal, $zohoDealId) : null,
            ];
        });
    }

    protected function createDeal(ZohoRecordsModule $dealModule, Deal $deal, array &$zohoData): ?string
    {
        try {
            $zohoDealId = $this->createRecord($dealModule, $zohoData)?->getId();
        } catch (ApiError $e) {
            Sentry::withScope(function ($scope) use ($zohoData, $deal, $e) {
                $scope->setContext('Deal Zoho Data', [
                    'zohoData' => $zohoData,
                    'dealId' => $deal->getId(),
                    'details' => $e->details(),
                    'message' => (string) $e->response()->getBody(),
                ]);

                report($e);
            });

            return null;
        }

        if ($zohoDealId === null || $zohoDealId === '') {
            return null;
        }

        $deal->set(CustomFieldEnum::ZOHO_DEAL_ID->value, $zohoDealId);

        return $zohoDealId;
    }

    /**
     * @return array{uploaded: int[], failed: array<int, string>}
     */
    protected function uploadAttachments(ZohoRecordsModule $dealModule, Deal $deal, string $zohoDealId): array
    {
        $files = $deal->files()->get();

        if ($files->isEmpty()) {
            return ['uploaded' => [], 'failed' => []];
        }

        $pushed = $this->filePusher($dealModule, $zohoDealId)->execute(
            $files,
            $this->syncedFileIds($deal, CustomFieldEnum::ZOHO_DEAL_SYNC_FILES)
        );

        $this->rememberSyncedFiles($deal, CustomFieldEnum::ZOHO_DEAL_SYNC_FILES, $pushed['uploaded']);

        return $pushed;
    }

    protected function resolveDealModule(Deal $deal): ZohoRecordsModule
    {
        return new ZohoService($deal->app, $deal->company)->getDealModule();
    }

    protected function filePusher(ZohoRecordsModule $dealModule, string $zohoDealId): PushFilesToZohoRecordAction
    {
        return new PushFilesToZohoRecordAction($dealModule, $zohoDealId);
    }
}
