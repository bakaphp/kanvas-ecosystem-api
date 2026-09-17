<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\ParkingApplications\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationFieldEnum as CorporateField;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\ParkingApplications\Enums\ParkingApplicationFieldEnum as Field;
use Kanvas\Connectors\Movipass\ParkingApplications\Enums\ParkingApplicationStatusEnum;
use Kanvas\Event\Events\Actions\SetResourceScheduleAction;
use Kanvas\Event\Events\Enums\ScheduleTypeEnum;
use Kanvas\Event\Events\Models\ScheduleException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Actions\CreateProductTypeAction;
use Kanvas\Inventory\ProductsTypes\DataTransferObject\ProductsTypes as ProductTypeDto;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

/**
 * Turns an approved parking application (a Lead carrying the catalog custom fields) into the
 * owner company's published parking: product + variant + warehouse row, opening hours as
 * schedule rules, closures as blackout exceptions, photos carried over in order.
 *
 * The wizard never persisted anything as domain — every value was stored verbatim by the
 * receiver (no validation at intake by design), so this is the one place the catalog validator
 * runs, and a value that survived nine steps malformed fails here with the key that broke,
 * before anything is written.
 */
class PublishParkingApplicationAction
{
    public const string PRODUCT_TYPE_SLUG = 'parking';

    private const array WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

    private const array INFRASTRUCTURE = [
        'lighting' => Field::HAS_LIGHTING,
        'cameras' => Field::HAS_CAMERAS,
        'camera_count' => Field::CAMERA_COUNT,
        'guard' => Field::HAS_GUARD,
        'roof' => Field::HAS_ROOF,
        'access_control' => Field::HAS_ACCESS_CONTROL,
        'restrooms' => Field::HAS_RESTROOMS,
        'security_24_7' => Field::IS_24_7_SECURITY,
    ];

    public function __construct(
        protected readonly Lead $application,
    ) {
    }

    public function execute(): Products
    {
        $company = Companies::getById((int) CorporateField::COMPANY_ID->readFrom($this->application));

        $existing = $this->alreadyPublished($company);

        if ($existing !== null) {
            return $existing;
        }

        $fields = $this->validatedFields();
        $contract = new StampContractAcceptanceAction($this->application)->execute();
        $warehouse = $this->defaultWarehouse($company);

        $product = DB::connection('inventory')->transaction(function () use ($company, $fields, $warehouse, $contract): Products {
            $product = new CreateProductAction(
                $this->productDto($company, $fields, $warehouse, $contract),
                $company->user,
            )->setRunWorkflow(false)->execute();

            /** @var Variants $variant */
            $variant = $product->variants()->firstOrFail();

            $this->writeSchedule($variant, $company, $fields);
            $this->writeClosures($variant, $company, $fields);

            return $product;
        });

        Field::PRODUCT_ID->writeTo($this->application, (string) $product->getId());
        Field::STATUS->writeTo($this->application, ParkingApplicationStatusEnum::PUBLISHED->value);

        return $product;
    }

    private function alreadyPublished(Companies $company): ?Products
    {
        $productId = (int) Field::PRODUCT_ID->readFrom($this->application);

        if ($productId === 0) {
            return null;
        }

        return Products::query()
            ->fromApp($this->application->app)
            ->fromCompany($company)
            ->notDeleted()
            ->find($productId);
    }

    /**
     * @return array<string, mixed> catalog key => normalized value
     */
    private function validatedFields(): array
    {
        $raw = [];

        foreach (Field::cases() as $field) {
            if ($field->step() === null || $field->isSensitive()) {
                continue;
            }

            $value = $field->readFrom($this->application);

            if ($value !== null && $value !== '') {
                $raw[$field->value] = $value;
            }
        }

        $fields = new ValidateParkingApplicationStepAction($raw)->execute();

        foreach ([Field::PARKING_NAME, Field::CAPACITY_TOTAL, Field::RATE_HOURLY, Field::LATITUDE, Field::LONGITUDE] as $required) {
            if (! isset($fields[$required->value])) {
                throw new ValidationException("{$required->value} is required to publish the parking");
            }
        }

        if (! empty($fields[Field::COORDINATES_PENDING_VALIDATION->value])) {
            throw new ValidationException('coordinates are still pending validation');
        }

        return $fields;
    }

    private function defaultWarehouse(Companies $company): Warehouses
    {
        $warehouse = Warehouses::query()
            ->fromApp($this->application->app)
            ->fromCompany($company)
            ->notDeleted()
            ->orderByDesc('is_default')
            ->first();

        if ($warehouse === null) {
            throw new ValidationException("company {$company->getId()} has no warehouse; onboarding did not run");
        }

        return $warehouse;
    }

    private function productDto(
        Companies $company,
        array $fields,
        Warehouses $warehouse,
        array $contract
    ): ProductDto {
        $name = (string) $fields[Field::PARKING_NAME->value];
        $capacity = (int) $fields[Field::CAPACITY_TOTAL->value];

        return new ProductDto(
            app: $this->application->app,
            company: $company,
            user: $company->user,
            name: $name,
            description: $this->description($fields),
            productsType: $this->parkingType($company),
            is_published: true,
            attributes: [...$this->productAttributes($fields, $capacity), ['name' => 'contract', 'value' => $contract]],
            files: $this->photos(),
            variants: [[
                'name' => $name,
                'sku' => 'parking-' . $this->application->uuid,
                'description' => $fields[Field::CAPACITY_NOTES->value] ?? null,
                'warehouses' => [[
                    'id' => $warehouse->getId(),
                    'quantity' => $capacity,
                    'max_capacity' => $capacity,
                    'price' => (float) $fields[Field::RATE_HOURLY->value],
                    'latitude' => (float) $fields[Field::LATITUDE->value],
                    'longitude' => (float) $fields[Field::LONGITUDE->value],
                    'config' => $this->warehouseConfig($fields),
                ]],
            ]],
        );
    }

    private function description(array $fields): string
    {
        return implode(', ', array_filter([
            $fields[Field::ADDRESS->value] ?? null,
            $fields[Field::CITY->value] ?? null,
            $fields[Field::PROVINCE->value] ?? null,
            $fields[Field::REFERENCES->value] ?? null,
        ]));
    }

    private function parkingType(Companies $company): ProductsTypes
    {
        return new CreateProductTypeAction(
            new ProductTypeDto(
                company: $company,
                user: $company->user,
                name: 'Parking',
                slug: self::PRODUCT_TYPE_SLUG,
            ),
            $company->user,
        )->execute();
    }

    /**
     * The attribute names Parkeando's storefront already reads off a parking product
     * (`coordinates`, `parking-hours`, `type`, `capacity`) — the ones SyncProductCapacityActivity
     * keeps refreshing afterwards.
     */
    private function productAttributes(array $fields, int $capacity): array
    {
        $attributes = [
            ['name' => 'coordinates', 'value' => [
                'lat' => (float) $fields[Field::LATITUDE->value],
                'long' => (float) $fields[Field::LONGITUDE->value],
            ]],
            ['name' => 'capacity', 'value' => [
                'totalParkingSpaces' => $capacity,
                'availableParkingSpaces' => $capacity,
                'occupiedParkingSpaces' => 0,
            ]],
        ];

        if (isset($fields[Field::PARKING_TYPE->value])) {
            $attributes[] = ['name' => 'type', 'value' => $fields[Field::PARKING_TYPE->value]];
        }

        $hours = $this->parkingHours($fields);

        if ($hours !== null) {
            $attributes[] = ['name' => 'parking-hours', 'value' => $hours];
        }

        return $attributes;
    }

    private function parkingHours(array $fields): ?array
    {
        if (! empty($fields[Field::IS_24_7->value])) {
            return ['open' => '00:00', 'close' => '23:59'];
        }

        $weekdays = $fields[Field::SCHEDULE->value]['weekdays'][0] ?? null;

        return $weekdays === null ? null : ['open' => $weekdays['open'], 'close' => $weekdays['close']];
    }

    private function warehouseConfig(array $fields): array
    {
        $config = [
            'rates' => [
                'hourly' => (float) $fields[Field::RATE_HOURLY->value],
                'minimum_entry' => $fields[Field::RATE_MINIMUM_ENTRY->value] ?? null,
                'overnight' => $fields[Field::RATE_OVERNIGHT->value] ?? null,
                'monthly' => $fields[Field::RATE_MONTHLY->value] ?? null,
            ],
            'capacity' => [
                'light_vehicles' => $fields[Field::CAPACITY_LIGHT_VEHICLES->value] ?? null,
                'motorcycles' => $fields[Field::CAPACITY_MOTORCYCLES->value] ?? null,
                'disability' => $fields[Field::CAPACITY_DISABILITY->value] ?? null,
                'numbered_spaces' => $fields[Field::NUMBERED_SPACES->value] ?? null,
            ],
            'payment_methods' => $fields[Field::PAYMENT_METHODS->value] ?? ['movipass'],
            'structure' => $fields[Field::STRUCTURE->value] ?? null,
            'is_24_7' => (bool) ($fields[Field::IS_24_7->value] ?? false),
            'schedule' => $fields[Field::SCHEDULE->value] ?? null,
            'closures' => $fields[Field::CLOSURES->value] ?? [],
        ];

        foreach (self::INFRASTRUCTURE as $key => $field) {
            $config['infrastructure'][$key] = $fields[$field->value] ?? null;
        }

        return $config;
    }

    /**
     * @return list<array{url: string, name: string}>
     */
    private function photos(): array
    {
        return $this->application->getFiles()
            ->map(fn ($file): array => ['url' => $file['url'], 'name' => $file['field_name'] ?: $file['name']])
            ->values()
            ->all();
    }

    /**
     * Opening hours become weekly schedule rules on the variant, without time slots — a parking
     * is capacity-based (GetSlotAvailabilityAction counts active orders against max_capacity),
     * the rules only say when it is open.
     */
    private function writeSchedule(Variants $variant, Companies $company, array $fields): void
    {
        $closedWeekdays = collect($fields[Field::CLOSURES->value] ?? [])
            ->where('type', 'recurring')
            ->pluck('weekday')
            ->all();

        $dayNames = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        $days = [];

        foreach ($dayNames as $weekday => $day) {
            $bands = $this->bandsFor($day, $fields);
            $days[$day] = $bands === [] || in_array($weekday, $closedWeekdays, true)
                ? ['active' => false]
                : ['active' => true, 'periods' => $bands];
        }

        new SetResourceScheduleAction(
            resource: $variant,
            app: $this->application->app,
            company: $company,
            days: $days,
            scheduleType: ScheduleTypeEnum::WEEKLY,
            generateSlots: false,
        )->execute();
    }

    private function bandsFor(string $day, array $fields): array
    {
        if (! empty($fields[Field::IS_24_7->value])) {
            return [['open' => '00:00', 'close' => '23:59']];
        }

        $schedule = $fields[Field::SCHEDULE->value] ?? [];

        $group = match (true) {
            in_array($day, self::WEEKDAYS, true) => 'weekdays',
            $day === 'saturday' => 'saturday',
            default => 'sunday_holidays',
        };

        return array_values(array_map(
            fn (array $band): array => $band['close'] === '00:00' ? ['open' => $band['open'], 'close' => '23:59'] : $band,
            $schedule[$group] ?? [],
        ));
    }

    private function writeClosures(Variants $variant, Companies $company, array $fields): void
    {
        foreach ($fields[Field::CLOSURES->value] ?? [] as $closure) {
            if ($closure['type'] !== 'one_off') {
                continue;
            }

            $day = Carbon::parse($closure['date']);

            ScheduleException::create([
                'apps_id' => $this->application->apps_id,
                'companies_id' => $company->getId(),
                'resources_id' => $variant->getId(),
                'resources_type' => $variant->getMorphClass(),
                'window_start' => $day->copy()->startOfDay(),
                'window_end' => $day->copy()->endOfDay(),
                'kind' => 'blackout',
                'reason' => $closure['reason'] ?? null,
            ]);
        }
    }
}
