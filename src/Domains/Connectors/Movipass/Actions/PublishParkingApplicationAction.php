<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationFieldEnum as CorporateField;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Enums\ParkingApplicationFieldEnum as Field;
use Kanvas\Connectors\Movipass\Enums\ParkingApplicationStatusEnum;
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

class PublishParkingApplicationAction
{
    public const string PRODUCT_TYPE_SLUG = 'parking';

    private const array DAY_NAMES = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
    private const array WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
    private const array FULL_DAY = ['open' => '00:00', 'close' => '23:59'];

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
        $warehouse = $this->defaultWarehouse($company);
        $contract = new StampContractAcceptanceAction($this->application)->execute();

        $product = DB::connection('inventory')->transaction(function () use ($company, $fields, $warehouse, $contract): Products {
            $product = new CreateProductAction(
                $this->productDto(
                    $company,
                    $fields,
                    $warehouse,
                    $contract
                ),
                $company->user,
            )->setRunWorkflow(false)->execute();

            $variant = $product->variants()->notDeleted()->firstOrFail();

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

    private function validatedFields(): array
    {
        $raw = [];

        foreach ($this->application->getAll() as $key => $value) {
            $field = Field::tryFrom((string) $key);

            if ($field === null || $field->step() === null || $field->isSensitive()) {
                continue;
            }

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
            attributes: [
                ...$this->productAttributes($fields, $capacity),
                ['name' => 'contract', 'value' => ['version' => $contract['version'], 'accepted_at' => $contract['accepted_at']]],
            ],
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
        return $this->bandsFor('monday', $fields)[0] ?? null;
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

    private function photos(): array
    {
        return $this->application->getFiles()
            ->map(fn ($file): array => ['url' => $file['url'], 'name' => $file['field_name'] ?: $file['name']])
            ->values()
            ->all();
    }

    private function writeSchedule(Variants $variant, Companies $company, array $fields): void
    {
        $closedWeekdays = collect($fields[Field::CLOSURES->value] ?? [])
            ->where('type', 'recurring')
            ->pluck('weekday')
            ->all();

        $bandsByDay = [];

        foreach (self::DAY_NAMES as $weekday => $day) {
            $bandsByDay[$day] = in_array($weekday, $closedWeekdays, true) ? [] : $this->bandsFor($day, $fields);
        }

        $days = [];

        foreach ($this->spillMidnightBands($bandsByDay) as $day => $bands) {
            $days[$day] = $bands === [] ? ['active' => false] : ['active' => true, 'periods' => $bands];
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

    private function spillMidnightBands(array $bandsByDay): array
    {
        foreach (self::DAY_NAMES as $weekday => $day) {
            foreach ($bandsByDay[$day] as $index => $band) {
                if ($band['close'] > $band['open']) {
                    continue;
                }

                $bandsByDay[$day][$index] = ['open' => $band['open'], 'close' => self::FULL_DAY['close']];
                array_unshift($bandsByDay[self::DAY_NAMES[($weekday + 1) % 7]], ['open' => '00:00', 'close' => $band['close']]);
            }
        }

        return $bandsByDay;
    }

    private function bandsFor(string $day, array $fields): array
    {
        if (! empty($fields[Field::IS_24_7->value])) {
            return [self::FULL_DAY];
        }

        $schedule = $fields[Field::SCHEDULE->value] ?? [];

        $group = match (true) {
            in_array($day, self::WEEKDAYS, true) => 'weekdays',
            $day === 'saturday' => 'saturday',
            default => 'sunday_holidays',
        };

        return array_values(array_map(
            fn (array $band): array => $band['close'] === '00:00' ? ['open' => $band['open'], 'close' => self::FULL_DAY['close']] : $band,
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
