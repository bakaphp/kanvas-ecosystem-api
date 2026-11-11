<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsPackagedTwigTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Kanvas\Enums\AppEnums;
use Kanvas\Scribe\Notifications\Enums\ScribeEmailTemplateEnum;
use Kanvas\Templates\Enums\EmailTemplateEnum;

/**
 * The Scribe emails (AR/AP daily digest, payment run) as Twig rows on apps_id 0, which TemplatesRepository
 * falls back to for every app; SyncEmailTemplateAction also writes an editable app-level copy.
 *
 * A seeder and not a migration, ordered after TemplateSeeder for the reason given on
 * CustomerUpdateEmailTemplateSeeder (explicit ids there). The rows hang under the platform `default`
 * layout so they arrive wrapped like every other email.
 */
class ScribeEmailTemplateSeeder extends Seeder
{
    use SeedsPackagedTwigTemplate;

    public function run(): void
    {
        $appsId = AppEnums::LEGACY_APP_ID->getValue();

        $parentId = DB::table('email_templates')
            ->where('name', EmailTemplateEnum::DEFAULT->value)
            ->where('apps_id', $appsId)
            ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
            ->where('is_deleted', 0)
            ->value('id');
        $parentId = $parentId !== null ? (int) $parentId : null;

        foreach (ScribeEmailTemplateEnum::cases() as $template) {
            self::storePackagedTemplate(
                $template->value,
                $template->title(),
                $template->file(),
                $appsId,
                parentTemplateId: $parentId
            );
        }
    }
}
