<?php

/**
 * One-off migration: for a fixed list of dealer companies (matched by name), finds vehicles
 * with more than one photo and a warehouse price, and pushes them into a Channel and/or a
 * secondary search index.
 *
 * Usage (production):
 *   php artisan tinker < scripts/push-company-vehicles-to-channel.tinker.php
 *
 * Edit the CONFIG block below before running. $CONFIRM = false only prints a preview — nothing
 * is written until you set it to true.
 */

use Baka\Search\Activities\PushEntityToSecondaryIndexActivity;
use Illuminate\Support\Facades\App;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Channels\Repositories\ChannelRepository;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\DataTransferObject\VariantChannel;
use Kanvas\Inventory\Variants\Services\VariantService;
use Kanvas\Workflow\Models\StoredWorkflow;

// ---- CONFIG — edit before running ----
$APP_ID = 2;
$CHANNEL_ID = null;       // e.g. 3097, or null to skip channel membership
$INDEX_NAME = null;       // e.g. 'prod-products-popular_index', or null to skip
$SEARCH_ENGINE = 'algolia';
$CONFIRM = false;         // false = preview only, nothing written
$LIMIT = null;            // cap total vehicles processed, or null for no cap
// ---------------------------------------

$COMPANY_NAMES = [
    'Alberic Auto Bayamon',
    'Alberic Colon Chrysler',
    'Alberic Colon Mitsubishi',
    'All Brand Auto',
    'Ambar Infiniti',
    'Atlantic Toyota Hatillo',
    'Audi San Juan',
    'Auto Amigo',
    'Auto Ofertas Caguas',
    'Auto Stop Aguada',
    'Auto Stop Kia',
    'Autocentro Chrysler',
    'Autocentro Más Guaynabo',
    'Autocentro Más San Juan',
    'Autogermana BMW',
    'Autogermana Mini',
    'Autogermana Pre-Owned',
    'AutoGrupo Kia',
    'AutoGrupo Nissan 65',
    'AutoGrupo Nissan Kennedy',
    'Autos Vega Ford',
    'Autos Vega Hormigueros',
    'Aviles Auto',
    'Braulio Agosto Mitsubishi',
    'Braulio Agosto Toyota',
    'Cabrera Ford',
    'Cabrera GM',
    'Caguas Expressway Motors',
    'CarFest',
    'Central Ford',
    'Ebenezer Auto',
    'Eurojapón Distributors',
    'Flagship Acura de Ponce',
    'Flagship Acura de San Juan',
    'Flagship Ford Carolina',
    'Flagship Ford del Sur',
    'Flagship Ford Rio Grande',
    'Flagship Honda de Bayamón',
    'Flagship Honda de Caguas',
    'Flagship Honda de Cayey',
    'Flagship Honda de Ponce',
    'Flagship Honda de Río Grande',
    'Flagship Honda de San Juan',
    'Flagship Hyundai Carolina',
    'Flagship Hyundai Escorial',
    'Flagship Jeep RAM',
    'Flagship Kia Carolina',
    'Flagship Mazda Bayamón',
    'Flagship Mazda Carolina',
    'Flagship Mazda Cayey',
    'Flagship Mazda Kennedy',
    'Flagship Mazda Ponce',
    'Flagship Mazda Rio Grande',
    'Flagship Mitsubishi Carolina',
    'Flagship Volkswagen Bayamón',
    'Flagship Volkswagen Ponce',
    'Garage Isla Verde',
    'Hyundai De Bayamón',
    'Hyundai de Caguas',
    'Hyundai de Hormigueros',
    'Hyundai de San Sebastian',
    'Hyundai De Vega Alta',
    'Hyundai Rexville',
    'JR Automotive',
    'Magic Auto Corp',
    'Medina Auto Kia',
    'Medina Auto Nissan',
    'MVP Toyota Caguas',
    'New Era Wholesaler',
    'Nissan de Aguadilla',
    'Pita Auto Sales',
    'San Juan Lincoln',
    'Señorial Auto Kia',
    'Señorial Auto Mitsubishi',
    'Taino Motors',
    'Tocars Kia',
    'Tocars Toa Baja',
    'Tocars Vega Baja',
    "Toñito D`Outlet",
    'Triangle Chrysler Ponce',
    'Triangle Dealer Del Oeste',
    'Triangle Fajardo',
    'Villa Victoria Auto',
    'Volkswagen Kennedy',
    'Volvo Car Puerto Rico',
    'Yokomuro Kia Bayamón',
    'Yokomuro Nissan',
];

if (! $CHANNEL_ID && ! $INDEX_NAME) {
    echo "Set \$CHANNEL_ID or \$INDEX_NAME before running.\n";

    return;
}

$app = Apps::getById($APP_ID);

// Equivalent to KanvasJobsTrait::overwriteAppService() — inlined since this is a plain script,
// not a Job/Command instance to attach the trait to.
App::scoped(Apps::class, fn () => $app);
Bouncer::scope()->to(RolesEnums::getScope($app));

$matched = [];
$unmatchedNames = [];
$ambiguousNames = [];

foreach ($COMPANY_NAMES as $name) {
    $exact = Companies::query()->fromApp($app)->notDeleted()->where('name', $name)->first();

    if ($exact) {
        $matched[$name] = $exact;

        continue;
    }

    $candidates = Companies::query()->fromApp($app)->notDeleted()->where('name', 'like', '%' . $name . '%')->get();

    if ($candidates->count() === 1) {
        $matched[$name] = $candidates->first();
    } elseif ($candidates->count() > 1) {
        $ambiguousNames[] = $name;
    } else {
        $unmatchedNames[] = $name;
    }
}

echo "\nMatched " . count($matched) . " compan(ies):\n";
foreach ($matched as $name => $company) {
    echo "  {$name} -> company {$company->id}\n";
}

if ($unmatchedNames) {
    echo "\nNo match found (0 candidates), skipped:\n";
    foreach ($unmatchedNames as $name) {
        echo "  {$name}\n";
    }
}

if ($ambiguousNames) {
    echo "\nMore than one candidate matched, skipped — needs manual review:\n";
    foreach ($ambiguousNames as $name) {
        echo "  {$name}\n";
    }
}

if (! $matched) {
    echo "No companies matched, nothing to do.\n";

    return;
}

// Assumes a shared/global (companies_id=0) channel — getByIdOrGlobal() only needs *a* company to
// build the query, and a global channel resolves the same regardless of which one is passed.
$channel = $CHANNEL_ID ? ChannelRepository::getByIdOrGlobal((int) $CHANNEL_ID, reset($matched), $app) : null;

$processed = 0;
$succeeded = 0;
$failed = 0;

foreach ($matched as $name => $company) {
    $vehicles = Products::query()
        ->fromApp($app)
        ->fromCompany($company)
        ->notDeleted()
        ->with('variants')
        ->whereHas('variants.variantWarehouses', function ($query) {
            $query->where('price', '>', 0)->where('is_deleted', 0);
        })
        ->get()
        ->filter(fn (Products $product) => $product->getPhotosCount() > 1)
        ->values();

    echo "\n{$name} (company {$company->id}): {$vehicles->count()} qualifying vehicle(s)\n";

    foreach ($vehicles as $product) {
        if ($LIMIT !== null && $processed >= $LIMIT) {
            break 2;
        }

        $processed++;
        echo "  - product {$product->id} ({$product->name})\n";

        if (! $CONFIRM) {
            continue;
        }

        try {
            $variant = $product->variants->first();

            if (! $variant) {
                throw new RuntimeException("Product {$product->id} has no variant");
            }

            if ($channel) {
                $variantWarehouse = $variant->variantWarehouses()
                    ->where('price', '>', 0)
                    ->where('is_deleted', 0)
                    ->first();

                if (! $variantWarehouse) {
                    throw new RuntimeException("No priced warehouse row found for variant {$variant->id}");
                }

                VariantService::addVariantChannel(
                    $variant,
                    $variantWarehouse->warehouse,
                    $channel,
                    new VariantChannel(
                        price: (float) $variantWarehouse->price,
                        is_published: true,
                    )
                );
            }

            if ($INDEX_NAME) {
                $activity = new PushEntityToSecondaryIndexActivity(0, now()->toDateTimeString(), new StoredWorkflow(), []);

                $result = $activity->execute($product, $app, [
                    'index_name' => $INDEX_NAME,
                    'search_engine' => $SEARCH_ENGINE,
                ]);

                if (! $result['result']) {
                    throw new RuntimeException($result['message'] ?? 'Unknown PushEntityToSecondaryIndexActivity failure');
                }
            }

            $succeeded++;
        } catch (Throwable $e) {
            $failed++;
            report($e);
            echo "    failed: {$e->getMessage()}\n";
        }
    }
}

if (! $CONFIRM) {
    echo "\nPreview only, nothing was written. Set \$CONFIRM = true to apply.\n";
} else {
    echo "\nDone. Processed: {$processed}, Succeeded: {$succeeded}, Failed: {$failed}\n";
}
