<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Exporters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Neuron\Exporters\Traits\ReadsExportFilters;
use Kanvas\Souk\Affiliates\Models\Affiliate;
use Kanvas\Souk\Affiliates\Models\AffiliateConversion;
use Kanvas\Souk\Affiliates\Models\AffiliateLink;
use Override;

/**
 * One row per attributed order with its commission breakdown — the affiliate commission report.
 * Conversions carry no companies_id, so tenant scope goes through the affiliate relation.
 */
class AffiliateCommissionsRecordExporter implements RecordExporterInterface
{
    use ReadsExportFilters;

    #[Override]
    public function type(): string
    {
        return 'affiliate_commissions';
    }

    #[Override]
    public function filtersHint(): string
    {
        return 'optional affiliate (code/unique id/name/email or link short code, e.g. "UA20"), '
            . 'status (pending|confirmed|paid|reversed|disputed), from_date, to_date (ISO, on converted_at)';
    }

    #[Override]
    public function headers(): array
    {
        return [
            'Order Number',
            'Order Date',
            'Customer',
            'Affiliate',
            'Affiliate Code',
            'Order Total',
            'Eligible Amount',
            'Commission Type',
            'Commission Rate',
            'Commission Amount',
            'Status',
            'Confirmed',
            'Converted At',
        ];
    }

    #[Override]
    public function rows(Apps $app, Companies $company, array $filters): array
    {
        $affiliateIds = $this->resolveAffiliateIds($app, $company, $this->filterString($filters, 'affiliate'));
        $status = $this->filterString($filters, 'status');
        $from = $this->filterString($filters, 'from_date');
        $to = $this->filterString($filters, 'to_date');

        $conversions = AffiliateConversion::query()
            ->where('apps_id', $app->getId())
            // A conversion carries no companies_id — anchor the tenant on the ORDER it belongs to, so an
            // order in this company always surfaces its commission even if the affiliate row is filed
            // under a different company. The affiliate filter only narrows within that.
            ->whereHas('order', fn (Builder $order) => $order->where('companies_id', $company->getId()))
            ->when($affiliateIds !== null, fn (Builder $q) => $q->whereIn('affiliates_id', $affiliateIds))
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
            ->when($from !== null, fn (Builder $q) => $q->where('converted_at', '>=', Carbon::parse((string) $from)->startOfDay()))
            ->when($to !== null, fn (Builder $q) => $q->where('converted_at', '<=', Carbon::parse((string) $to)->endOfDay()))
            ->with(['affiliate', 'order.people', 'link'])
            ->orderByDesc('converted_at')
            ->limit(RecordExporterRegistry::MAX_ROWS)
            ->get();

        return $conversions->map(function (AffiliateConversion $conversion): array {
            $order = $conversion->order;
            $affiliate = $conversion->affiliate;
            $people = $order?->people;

            $customer = $people !== null ? trim($people->firstname . ' ' . $people->lastname) : '';
            if ($customer === '') {
                $customer = $order?->user_email ?? '';
            }

            return [
                $order?->order_number ?? '',
                $conversion->converted_at?->toDateString() ?? $order?->created_at?->toDateString() ?? '',
                $customer,
                $affiliate?->name ?? '',
                $conversion->link?->short_code ?? $affiliate?->unique_identifier ?? '',
                (float) $conversion->order_total,
                (float) $conversion->eligible_amount,
                $conversion->commission_type,
                (float) $conversion->commission_rate,
                (float) $conversion->commission_amount,
                $conversion->status,
                $conversion->confirmed ? 'yes' : 'no',
                $conversion->converted_at?->toDateTimeString() ?? '',
            ];
        })->all();
    }

    /**
     * Resolve the affiliate filter to a company-scoped list of affiliate ids, matching an affiliate by
     * its unique identifier / email / name, or by one of its link short codes. Returns null when no
     * affiliate filter was given (the caller then scopes by company through the affiliate relation).
     *
     * @return list<int>|null
     */
    private function resolveAffiliateIds(Apps $app, Companies $company, ?string $affiliate): ?array
    {
        if ($affiliate === null) {
            return null;
        }

        $byAffiliate = Affiliate::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_deleted', false)
            ->where(function (Builder $query) use ($affiliate) {
                $query->where('unique_identifier', $affiliate)
                    ->orWhere('email', $affiliate)
                    ->orWhere('name', 'like', '%' . $affiliate . '%');
            })
            ->pluck('id')
            ->all();

        $byLink = AffiliateLink::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('short_code', $affiliate)
            ->pluck('affiliates_id')
            ->all();

        $ids = array_values(array_unique([...$byAffiliate, ...$byLink]));

        if ($ids === []) {
            throw new ValidationException('No affiliate found matching "' . $affiliate . '" for this company.');
        }

        return $ids;
    }
}
