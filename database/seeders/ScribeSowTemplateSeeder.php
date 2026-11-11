<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsPackagedTwigTemplate;
use Illuminate\Database\Seeder;
use Kanvas\Enums\AppEnums;
use Kanvas\Scribe\Quotes\Sow\Services\QuoteSowPdfService;

/**
 * Ships the `scribe-sow` Statement of Work layout as a Twig row.
 *
 * A seeder and not a migration, ordered after TemplateSeeder for the reason given on
 * CustomerUpdateEmailTemplateSeeder (explicit ids there). run() writes the platform row (apps_id 0),
 * which TemplatesRepository falls back to for every app. forApp() writes an app-level copy that the
 * tenant can then edit; a company overrides either by adding a `scribe-sow` row of its own.
 */
class ScribeSowTemplateSeeder extends Seeder
{
    use SeedsPackagedTwigTemplate;

    public function run(): void
    {
        self::forApp(AppEnums::LEGACY_APP_ID->getValue());
    }

    public static function forApp(int $appsId, int $usersId = 1): bool
    {
        return self::storePackagedTemplate(
            QuoteSowPdfService::TEMPLATE_NAME,
            'Statement of Work',
            QuoteSowPdfService::PACKAGED_TEMPLATE,
            $appsId,
            $usersId
        );
    }
}
