<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsPackagedTwigTemplate;
use Illuminate\Database\Seeder;
use Kanvas\Enums\AppEnums;
use Kanvas\Scribe\Documents\Services\DocumentPdfService;

/**
 * Ships the invoice and quote printouts as two Twig rows, `scribe-invoice` and `scribe-quote`, from the one
 * packaged layout: a tenant restyles an invoice without touching its quotes. run() writes the platform rows;
 * forApp() an app-level copy the tenant edits, and a company overrides either with a row of its own.
 */
class ScribeDocumentTemplateSeeder extends Seeder
{
    use SeedsPackagedTwigTemplate;

    public function run(): void
    {
        self::forApp(AppEnums::LEGACY_APP_ID->getValue());
    }

    /**
     * @return bool whether any row was written
     */
    public static function forApp(int $appsId, int $usersId = 1): bool
    {
        $invoice = self::storePackagedTemplate(
            DocumentPdfService::INVOICE_TEMPLATE_NAME,
            'Invoice',
            DocumentPdfService::PACKAGED_TEMPLATE,
            $appsId,
            $usersId
        );

        $quote = self::storePackagedTemplate(
            DocumentPdfService::QUOTE_TEMPLATE_NAME,
            'Quote',
            DocumentPdfService::PACKAGED_TEMPLATE,
            $appsId,
            $usersId
        );

        return $invoice || $quote;
    }
}
