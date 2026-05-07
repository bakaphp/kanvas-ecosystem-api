<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Actions;

use Baka\Contracts\AppInterface;
use Exception;
use Illuminate\Support\Facades\DB;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\Models\Variants;
use SoapFault;
use Throwable;

class SyncAllNetSuiteCustomerItemsListAction
{
    public function __construct(
        protected AppInterface $app,
        protected bool $dryRun = false,
        protected array $onlyBuyerIds = []
    ) {
    }

    public function execute(): array
    {
        $mainCompanyId = $this->app->get('B2B_MAIN_COMPANY_ID');

        if (! $mainCompanyId) {
            throw new Exception('B2B_MAIN_COMPANY_ID is not configured for this app');
        }

        $mainCompany = Companies::getById($mainCompanyId);

        $totalProducts = $this->buildNetSuiteVariantQuery($mainCompany)->count();

        $buyersQuery = Companies::getByCustomFieldBuilder(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value, null)
            ->where('companies.id', '!=', $mainCompany->getId())
            ->where('companies.is_deleted', 0)
            ->whereIn('companies.id', function ($subquery) {
                $subquery->select('companies_id')
                    ->from('user_company_apps')
                    ->where('apps_id', $this->app->getId());
            });

        if (! empty($this->onlyBuyerIds)) {
            $buyersQuery->whereIn('companies.id', $this->onlyBuyerIds);
        }

        $buyers = $buyersQuery->get();

        $results = [];
        $totalSynced = 0;
        $totalSkipped = 0;
        $totalErrors = 0;

        foreach ($buyers as $buyer) {
            try {
                $channel = Channels::where('apps_id', $this->app->getId())
                    ->where('companies_id', $mainCompany->getId())
                    ->where('slug', (string) $buyer->uuid)
                    ->first();

                $channelFound = $channel !== null;
                $channelCount = $channelFound
                    ? $this->countNetSuiteVariantsInChannel($mainCompany, $channel)
                    : 0;

                $needsSync = ! $channelFound || $channelCount < $totalProducts;
                $synced = false;

                if ($needsSync && ! $this->dryRun) {
                    new SyncNetSuiteCustomerItemsListAction(
                        $this->app,
                        $mainCompany,
                        $buyer
                    )->execute();

                    $synced = true;
                    $totalSynced++;
                } elseif (! $needsSync) {
                    $totalSkipped++;
                }

                $results[] = [
                    'company_id' => $buyer->getId(),
                    'company_name' => $buyer->name,
                    'company_uuid' => (string) $buyer->uuid,
                    'channel_found' => $channelFound,
                    'product_count' => $totalProducts,
                    'channel_count' => $channelCount,
                    'missing_count' => max(0, $totalProducts - $channelCount),
                    'synced' => $synced,
                    'error' => null,
                ];
            } catch (SoapFault $e) {
                $totalErrors++;
                $status = $this->isNetSuiteRateLimitError($e) ? 'rate_limited' : 'error';

                $results[] = [
                    'company_id' => $buyer->getId(),
                    'company_name' => $buyer->name,
                    'company_uuid' => (string) $buyer->uuid,
                    'channel_found' => false,
                    'product_count' => $totalProducts,
                    'channel_count' => 0,
                    'missing_count' => 0,
                    'synced' => false,
                    'error' => ['status' => $status, 'message' => $e->getMessage()],
                ];
            } catch (Throwable $e) {
                $totalErrors++;

                $results[] = [
                    'company_id' => $buyer->getId(),
                    'company_name' => $buyer->name,
                    'company_uuid' => (string) $buyer->uuid,
                    'channel_found' => false,
                    'product_count' => $totalProducts,
                    'channel_count' => 0,
                    'missing_count' => 0,
                    'synced' => false,
                    'error' => ['status' => 'error', 'message' => $e->getMessage()],
                ];
            }
        }

        return [
            'main_company_id' => $mainCompany->getId(),
            'total_products' => $totalProducts,
            'total_buyers' => $buyers->count(),
            'total_synced' => $totalSynced,
            'total_skipped' => $totalSkipped,
            'total_errors' => $totalErrors,
            'dry_run' => $this->dryRun,
            'results' => $results,
        ];
    }

    protected function buildNetSuiteVariantQuery(Companies $mainCompany)
    {
        return Variants::getByCustomFieldBuilder(
            CustomFieldEnum::NET_SUITE_PRODUCT_ID->value,
            null,
            $mainCompany
        )
            ->where('products_variants.apps_id', $this->app->getId())
            ->where('products_variants.companies_id', $mainCompany->getId())
            ->where('products_variants.is_deleted', 0)
            ->where('products_variants.is_published', 1);
    }

    protected function countNetSuiteVariantsInChannel(Companies $mainCompany, Channels $channel): int
    {
        return $this->buildNetSuiteVariantQuery($mainCompany)
            ->whereExists(function ($query) use ($channel) {
                $query->select(DB::raw(1))
                    ->from('products_variants_channels')
                    ->whereColumn('products_variants_channels.products_variants_id', 'products_variants.id')
                    ->where('products_variants_channels.channels_id', $channel->getId())
                    ->where('products_variants_channels.is_deleted', 0);
            })
            ->count();
    }

    protected function isNetSuiteRateLimitError(SoapFault $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'concurrent request limit exceeded') ||
               str_contains($message, 'request blocked') ||
               str_contains($message, 'rate limit') ||
               str_contains($message, 'too many requests') ||
               str_contains($message, 'suitetalk concurrent request limit');
    }
}
