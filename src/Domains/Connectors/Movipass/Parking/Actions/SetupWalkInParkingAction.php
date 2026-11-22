<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Parking\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Workflows\Activities\ParkingExitDecisionActivity;
use Kanvas\Connectors\Movipass\Workflows\Activities\ParkingPassScannedActivity;
use Kanvas\Connectors\Movipass\Workflows\Activities\SyncMovipassActivity;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\ScheduleRules;
use Kanvas\Event\Passes\DataTransferObject\WalkInAccess;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Rules\Actions\WireRuleAction;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;

class SetupWalkInParkingAction
{
    public const string EXIT_DECISION_RULE_NAME = 'Walk-in parking: authorize the exit scan';
    public const string PASS_SCANNED_RULE_NAME = 'Walk-in parking: record the entry and exit scans';
    public const string CHECK_OK = 'ok';
    public const string CHECK_WARN = 'warn';
    private const string SUPPORT_REGION = 'SV';

    public function __construct(
        protected readonly Apps $app,
        protected readonly Products $product,
        protected readonly float $hourlyRate,
        protected readonly int $expireUnusedMinutes,
    ) {
    }

    public function execute(): array
    {
        $this->assertActivitiesAreSynced();
        $orderType = $this->resolveOrderType();
        $productTypeSlug = $this->product->productsType?->slug;

        if ($productTypeSlug === null) {
            throw new ValidationException('The product has no product type; walk-in parking bookings resolve the order type from it.');
        }

        $this->writeWalkInAttribute();
        $this->writeHourlyRate();
        $this->mergeBookingProductType($orderType, $productTypeSlug);
        $this->wireRules();

        return $this->checklist($orderType);
    }

    private function assertActivitiesAreSynced(): void
    {
        foreach ([ParkingExitDecisionActivity::class, ParkingPassScannedActivity::class] as $activity) {
            if (! Action::where('model_name', $activity)->exists()) {
                throw new ValidationException(sprintf(WireRuleAction::MISSING_ACTION, class_basename($activity)));
            }
        }
    }

    private function resolveOrderType(): OrderTypes
    {
        $orderType = OrderTypes::query()
            ->fromApp($this->app)
            ->notDeleted()
            ->where('name', OrderTypeEnum::MOVIPASS->value)
            ->whereIn('companies_id', [0, $this->product->companies_id])
            ->orderByRaw(
                'CASE WHEN companies_id = ? THEN 0 ELSE 1 END',
                [$this->product->companies_id]
            )
            ->first();

        if ($orderType === null) {
            throw new ValidationException('The movipass order type does not exist. Run `php artisan kanvas:movipass-setup-movipass-order` first.');
        }

        return $orderType;
    }

    private function writeWalkInAttribute(): void
    {
        $current = $this->product->getAttributeByName(WalkInAccess::ATTRIBUTE_NAME)?->value;
        $current = is_string($current) ? json_decode($current, true) : $current;

        $this->product->addAttribute(
            WalkInAccess::ATTRIBUTE_NAME,
            json_encode([
                ...(is_array($current) ? $current : []),
                'max_uses' => WalkInAccess::DEFAULT_MAX_USES,
                'decision_from_use' => WalkInAccess::DEFAULT_DECISION_FROM_USE,
                'expire_unused_minutes' => $this->expireUnusedMinutes,
            ])
        );
    }

    private function writeHourlyRate(): void
    {
        foreach ($this->product->variants as $variant) {
            foreach ($variant->variantWarehouses as $row) {
                $config = $row->config ?? [];
                $config['rates'] = [...($config['rates'] ?? []), 'hourly' => $this->hourlyRate];
                $row->config = $config;
                $row->save();
            }
        }
    }

    private function mergeBookingProductType(OrderTypes $orderType, string $productTypeSlug): void
    {
        $config = $orderType->config ?? [];
        $booking = $config[OrderTypes::EVENT_BOOKING] ?? [];
        $productTypes = $booking['product_types'] ?? [];

        $config[OrderTypes::EVENT_BOOKING] = [
            ...$booking,
            'product_types' => array_values(array_unique([...$productTypes, $productTypeSlug])),
        ];
        $orderType->config = $config;
        $orderType->saveOrFail();
    }

    private function wireRules(): void
    {
        $this->wire(
            WorkflowEnum::PASS_SCAN_REQUESTED->value,
            ParkingExitDecisionActivity::class,
            self::EXIT_DECISION_RULE_NAME,
            isAsync: false,
        );
        $this->wire(
            WorkflowEnum::PASS_SCANNED->value,
            ParkingPassScannedActivity::class,
            self::PASS_SCANNED_RULE_NAME,
            isAsync: true,
        );
    }

    private function wire(
        string $event,
        string $activity,
        string $name,
        bool $isAsync
    ): void {
        $rule = new WireRuleAction(
            app: $this->app,
            companyId: (int) $this->product->companies_id,
            entityClass: EventVersion::class,
            ruleTypeName: $event,
            activityClass: $activity,
            name: $name,
            description: 'Created by kanvas-movipass:setup-walk-in-parking',
            isAsync: $isAsync,
        )->execute();

        if ((bool) $rule->is_async !== $isAsync) {
            $rule->is_async = $isAsync;
            $rule->saveOrFail();
        }
    }

    private function checklist(OrderTypes $orderType): array
    {
        $variants = $this->product->variants;
        $company = $this->product->company;
        $rows = [];

        $rows[] = $this->row(
            'Company timezone',
            $company?->timezone === 'America/El_Salvador',
            (string) ($company?->timezone ?: 'not set (expected America/El_Salvador)')
        );
        $rows[] = $this->warehouseRegionRow($variants->all());
        $rows[] = $this->row(
            'Schedule rules',
            $this->hasScheduleRules($variants->all()),
            'the product or a variant needs operating-hours schedule rules'
        );
        $rows[] = $this->integrationRow();
        $rows[] = $this->gatewayRow();
        $rows[] = $this->expirationRow($orderType);
        $rows[] = $this->row(
            'Movipass order-updated rule',
            $this->hasOrderUpdatedRule(),
            'the payment confirmation hook runs from the Movipass sync activity on order updated'
        );

        return $rows;
    }

    private function row(string $label, bool $ok, string $detail): array
    {
        return [$label, $ok ? self::CHECK_OK : self::CHECK_WARN, $detail];
    }

    private function warehouseRegionRow(array $variants): array
    {
        $slugs = collect($variants)
            ->flatMap(fn (Variants $variant) => $variant->variantWarehouses)
            ->map(fn ($row) => $row->warehouse?->region?->slug)
            ->filter()
            ->unique()
            ->values();

        return $this->row(
            'Warehouse region',
            $slugs->isNotEmpty(),
            $slugs->isEmpty()
                ? 'no variant is stocked in a warehouse with a region, and the exit quote fails without an hourly rate on a variant-warehouse row'
                : $slugs->implode(', ')
        );
    }

    private function hasScheduleRules(array $variants): bool
    {
        $resources = collect($variants)->push($this->product);

        return $resources->contains(
            fn ($resource): bool => ScheduleRules::query()
                ->where('resources_type', $resource->getMorphClass())
                ->where('resources_id', $resource->getId())
                ->where('is_deleted', 0)
                ->exists()
        );
    }

    private function integrationRow(): array
    {
        $company = $this->product->company;
        $region = $company === null ? null : Regions::getDefault($company, $this->app);

        if ($region === null) {
            return $this->row('Movipass integration', false, 'the company has no default region; both parking activities need it');
        }

        $integration = IntegrationsCompany::getByIntegration(
            company: $company,
            status: Status::where('slug', StatusEnum::ACTIVE->value)->where('apps_id', 0)->firstOrFail(),
            name: IntegrationsEnum::MOVIPASS->value,
            region: $region,
            app: $this->app
        );

        return $this->row(
            'Movipass integration',
            $integration !== null,
            $integration !== null
                ? 'active for the default region ' . $region->slug
                : 'no active MOVIPASS integration for the default region; every exit is NOT_AUTHORIZED and entries never activate'
        );
    }

    private function gatewayRow(): array
    {
        $region = Regions::query()
            ->fromApp($this->app)
            ->notDeleted()
            ->where('companies_id', 0)
            ->where('short_slug', self::SUPPORT_REGION)
            ->first();
        $gateway = $region?->settings['default_payment_gateway'] ?? null;

        return $this->row(
            'SV region gateway',
            $gateway !== null,
            $gateway ?? 'run kanvas-movipass:set-region-gateway {app} SV payway'
        );
    }

    private function expirationRow(OrderTypes $orderType): array
    {
        $rule = $orderType->expirationRule();

        if ($rule === null) {
            return $this->row('Order type expiration rule', true, 'none configured');
        }

        $detail = sprintf(
            'from_status=%s date_path=%s; a walk-in order must not carry that date or it expires while the car is inside',
            $rule['from_status'],
            $rule['date_path']
        );

        return $this->row('Order type expiration rule', $rule['from_status'] !== 'created', $detail);
    }

    private function hasOrderUpdatedRule(): bool
    {
        return Rule::query()
            ->fromApp($this->app)
            ->notDeleted()
            ->whereIn('companies_id', [0, (int) $this->product->companies_id])
            ->whereHas('type', fn ($query) => $query->where('name', WorkflowEnum::UPDATED->value))
            ->where(
                'systems_modules_id',
                SystemModulesRepository::getByModelName(Order::class, $this->app)->getId()
            )
            ->whereHas(
                'workflowActivities.activity.action',
                fn ($query) => $query->where('model_name', SyncMovipassActivity::class)
            )
            ->exists();
    }
}
