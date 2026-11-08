<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsPackagedTwigTemplate;
use Illuminate\Database\Seeder;
use Kanvas\Enums\AppEnums;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfInvoicePdfService;

/**
 * Ships `scribe-ecf-invoice`, our own legal e-CF printout, as a Twig row (templates are seeders, never
 * migrations). run() writes the platform row; forApp() an app-level copy a tenant can edit, and a company
 * overrides either with a `scribe-ecf-invoice` row of its own.
 */
class ScribeEcfInvoiceTemplateSeeder extends Seeder
{
    use SeedsPackagedTwigTemplate;

    public function run(): void
    {
        self::forApp(AppEnums::LEGACY_APP_ID->getValue());
    }

    public static function forApp(int $appsId, int $usersId = 1): bool
    {
        return self::storePackagedTemplate(
            EcfInvoicePdfService::TEMPLATE_NAME,
            'e-CF (comprobante fiscal electrónico)',
            EcfInvoicePdfService::PACKAGED_TEMPLATE,
            $appsId,
            $usersId
        );
    }
}
