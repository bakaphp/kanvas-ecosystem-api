<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Kanvas\Enums\AppEnums;
use Kanvas\Intelligence\Agents\Services\CustomerSuccess\CustomerUpdateRenderer;

/**
 * Ships the monthly customer-update layout as a row so the look can change without a deploy.
 *
 * A seeder and not a migration, and ordered AFTER TemplateSeeder: that seeder inserts with explicit
 * ids (1, 2, ...), so anything that writes email_templates before it takes those ids on a fresh
 * database and breaks setup with a duplicate-primary-key error. Migrations always run before
 * db:seed, which is why this cannot be one.
 *
 * apps_id 0 is the fallback TemplatesRepository resolves for every app, so this one row dresses every
 * tenant; an app or a single company that wants its own adds a more specific row and wins on
 * precedence. CustomerUpdateRenderer falls back to a built-in shell when no row resolves, so this is
 * an upgrade to the look rather than something the send depends on.
 */
class CustomerUpdateEmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $path = resource_path('views/emails/customerUpdate.blade.php');

        if (! File::exists($path)) {
            return;
        }

        $exists = DB::table('email_templates')
            ->where('name', CustomerUpdateRenderer::TEMPLATE_NAME)
            ->where('apps_id', AppEnums::LEGACY_APP_ID->getValue())
            ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('email_templates')->insert([
            'apps_id' => AppEnums::LEGACY_APP_ID->getValue(),
            'companies_id' => AppEnums::GLOBAL_COMPANY_ID->getValue(),
            'users_id' => 1,
            'parent_template_id' => null,
            'name' => CustomerUpdateRenderer::TEMPLATE_NAME,
            'title' => 'Customer product update',
            'template' => File::get($path),
            'is_system' => 1,
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
