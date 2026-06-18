<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Support\Str;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\DataTransferObject\Company as CompanyData;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Connectors\Movipass\DataTransferObject\CorporateFleet;
use Kanvas\Connectors\Movipass\DataTransferObject\FleetVehicle;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Users\Models\Users;

class ImportCorporateFleetAction
{
    private const VEHICLE_PRODUCT_TYPE_SLUG = 'vehicle';

    public function __construct(
        protected readonly Apps $app,
        protected readonly Users $user,
        protected readonly CorporateFleet $fleet,
        protected readonly ?Companies $company = null,
        protected readonly bool $dryRun = false,
    ) {
    }

    public function execute(): array
    {
        $duplicatePlates = $this->findDuplicatePlates();

        // Corporate metadata is only validated when we create the company; an
        // explicit --company is assumed to be an already-onboarded corporate account.
        $validationError = $this->company === null ? $this->validateCorporateFields() : null;

        if (! $this->dryRun && $validationError !== null) {
            throw new ValidationException($validationError);
        }

        if ($this->dryRun) {
            return $this->buildDryRunSummary($duplicatePlates, $validationError);
        }

        $company = $this->company ?? $this->resolveCorporateCompany();
        $productType = $this->resolveVehicleProductType($company);

        $created = 0;
        $updated = 0;
        $skipped = [];
        $vehicles = [];

        foreach ($this->fleet->vehicles as $vehicle) {
            $key = $this->vehicleKey($vehicle);

            if ($key === '') {
                $skipped[] = ['vehicle' => $this->describe($vehicle), 'reason' => 'missing tag_number and plate'];

                continue;
            }

            $slug = $this->vehicleSlug($vehicle, $key);
            $existed = Products::where([
                'slug' => $slug,
                'apps_id' => $this->app->getId(),
                'companies_id' => $company->getId(),
            ])->exists();

            $product = $this->importVehicle($company, $productType, $vehicle, $slug, $key);

            $existed ? $updated++ : $created++;
            $vehicles[] = [
                'product_id' => $product->getId(),
                'name' => $product->name,
                'tag_number' => $vehicle->tagNumber,
                'plate' => $vehicle->plate,
                'action' => $existed ? 'updated' : 'created',
            ];
        }

        return [
            'dry_run' => false,
            'company_id' => $company->getId(),
            'company_name' => $company->name,
            'total' => count($this->fleet->vehicles),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'duplicate_plates' => $duplicatePlates,
            'vehicles' => $vehicles,
        ];
    }

    private function validateCorporateFields(): ?string
    {
        return new ValidateCorporateFieldsAction([
            'rnc' => $this->fleet->rnc,
            'legal_name' => $this->fleet->legalName,
            'contact_email' => $this->fleet->contactEmail ?? '',
        ])->execute();
    }

    private function resolveCorporateCompany(): Companies
    {
        $name = $this->fleet->companyName();

        $existing = CompaniesRepository::getCompanyByNameAndApp($name, $this->app);

        if ($existing !== null) {
            $this->applyCorporateFields($existing);

            return $existing;
        }

        return DB::connection('ecosystem')->transaction(function () use ($name) {
            $company = new CreateCompaniesAction(
                new CompanyData(
                    user: $this->user,
                    name: $name,
                    email: $this->fleet->contactEmail,
                    phone: $this->fleet->contactPhone,
                ),
            )->execute();

            $this->app->associateCompany($company);
            $this->applyCorporateFields($company);

            return $company;
        });
    }

    private function applyCorporateFields(Companies $company): void
    {
        $company->set('is_corporate', true);

        $fields = [
            'legal_name' => $this->fleet->legalName,
            'commercial_name' => $this->fleet->commercialName,
            'rnc' => $this->fleet->rnc,
            'contact_name' => $this->fleet->contactName,
            'contact_email' => $this->fleet->contactEmail,
            'contact_phone' => $this->fleet->contactPhone,
        ];

        foreach ($fields as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $company->set($key, $value);
        }
    }

    private function resolveVehicleProductType(Companies $company): ProductsTypes
    {
        $existing = ProductsTypes::where('slug', self::VEHICLE_PRODUCT_TYPE_SLUG)
            ->fromApp($this->app)
            ->notDeleted()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return ProductsTypes::firstOrCreate([
            'slug' => self::VEHICLE_PRODUCT_TYPE_SLUG,
            'apps_id' => $this->app->getId(),
            'companies_id' => $company->getId(),
        ], [
            'name' => 'Vehicle',
            'weight' => 0,
            'users_id' => $this->user->getId(),
            'is_published' => true,
        ]);
    }

    private function importVehicle(
        Companies $company,
        ProductsTypes $productType,
        FleetVehicle $vehicle,
        string $slug,
        string $sku,
    ): Products {
        return new CreateProductAction(
            new ProductDto(
                app: $this->app,
                company: $company,
                user: $this->user,
                name: $this->vehicleName($vehicle),
                productsType: $productType,
                sku: $sku,
                attributes: $this->vehicleAttributes($vehicle),
                slug: $slug,
            ),
            $this->user,
        )->setRunWorkflow(false)->execute();
    }

    /**
     * Attribute names mirror what CreateVehicleFromOrderActivity writes so the
     * order-driven and fleet-imported vehicles share one schema.
     */
    private function vehicleAttributes(FleetVehicle $vehicle): array
    {
        return array_values(array_filter([
            $vehicle->brand !== '' ? ['name' => 'brand', 'value' => $vehicle->brand] : null,
            $vehicle->model ? ['name' => 'model', 'value' => $vehicle->model] : null,
            $vehicle->year ? ['name' => 'year', 'value' => $vehicle->year] : null,
            $vehicle->vin ? ['name' => 'vin', 'value' => $vehicle->vin] : null,
            $vehicle->plate ? ['name' => 'plate', 'value' => $vehicle->plate] : null,
            $vehicle->tagNumber !== '' ? ['name' => 'tag_number', 'value' => $vehicle->tagNumber] : null,
        ]));
    }

    private function vehicleName(FleetVehicle $vehicle): string
    {
        $name = trim($vehicle->brand . ' ' . (string) $vehicle->model);
        $name = $name !== '' ? $name : 'Vehicle';

        return $vehicle->plate ? "{$name} ({$vehicle->plate})" : $name;
    }

    private function vehicleSlug(FleetVehicle $vehicle, string $key): string
    {
        return Str::slug(trim($vehicle->brand . ' ' . (string) $vehicle->model . ' ' . $key));
    }

    private function vehicleKey(FleetVehicle $vehicle): string
    {
        return $vehicle->tagNumber !== '' ? $vehicle->tagNumber : (string) ($vehicle->plate ?? '');
    }

    private function describe(FleetVehicle $vehicle): string
    {
        $label = trim($vehicle->brand . ' ' . (string) $vehicle->model);

        return $label !== '' ? $label : 'unknown vehicle';
    }

    /**
     * @return string[] plates that appear on more than one vehicle in the file
     */
    private function findDuplicatePlates(): array
    {
        $counts = [];

        foreach ($this->fleet->vehicles as $vehicle) {
            $plate = $vehicle->plate;

            if ($plate === null || $plate === '') {
                continue;
            }

            $counts[$plate] = ($counts[$plate] ?? 0) + 1;
        }

        return array_keys(array_filter($counts, static fn (int $count) => $count > 1));
    }

    /**
     * @param string[] $duplicatePlates
     */
    private function buildDryRunSummary(array $duplicatePlates, ?string $validationError): array
    {
        $vehicles = [];
        $skipped = [];

        foreach ($this->fleet->vehicles as $vehicle) {
            if ($this->vehicleKey($vehicle) === '') {
                $skipped[] = ['vehicle' => $this->describe($vehicle), 'reason' => 'missing tag_number and plate'];

                continue;
            }

            $vehicles[] = [
                'name' => $this->vehicleName($vehicle),
                'tag_number' => $vehicle->tagNumber,
                'plate' => $vehicle->plate,
                'missing_year' => $vehicle->year === null,
            ];
        }

        return [
            'dry_run' => true,
            'company_name' => $this->fleet->companyName(),
            'rnc' => $this->fleet->rnc,
            'validation_error' => $validationError,
            'total' => count($this->fleet->vehicles),
            'importable' => count($vehicles),
            'skipped' => $skipped,
            'duplicate_plates' => $duplicatePlates,
            'vehicles' => $vehicles,
        ];
    }
}
