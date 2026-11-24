<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Kanvas\Enums\AppEnums;

/**
 * Stores a packaged Twig file under resources/ as an `email_templates` row for one app (apps_id 0 is the
 * platform row TemplatesRepository falls back to). Never overwrites: a row that exists may already carry
 * the tenant's edits.
 */
trait SeedsPackagedTwigTemplate
{
    /**
     * @return bool whether a row was written
     */
    protected static function storePackagedTemplate(
        string $name,
        string $title,
        string $resourcePath,
        int $appsId,
        int $usersId = 1,
        ?int $parentTemplateId = null
    ): bool {
        $path = resource_path($resourcePath);

        if (! File::exists($path)) {
            return false;
        }

        $exists = DB::table('email_templates')
            ->where('name', $name)
            ->where('apps_id', $appsId)
            ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
            ->where('is_deleted', 0)
            ->exists();

        if ($exists) {
            return false;
        }

        DB::table('email_templates')->insert([
            'apps_id' => $appsId,
            'companies_id' => AppEnums::GLOBAL_COMPANY_ID->getValue(),
            'users_id' => $usersId,
            'parent_template_id' => $parentTemplateId,
            'name' => $name,
            'title' => $title,
            'template' => File::get($path),
            'engine' => 'twig',
            'is_system' => 1,
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }
}
