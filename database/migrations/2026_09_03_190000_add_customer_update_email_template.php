<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Kanvas\Enums\AppEnums;
use Kanvas\Intelligence\Agents\Services\CustomerSuccess\CustomerUpdateRenderer;

/**
 * Ships the monthly customer-update layout as a row so the look can change without a deploy.
 *
 * apps_id 0 is the fallback TemplatesRepository resolves for every app, so this one row dresses every
 * tenant; an app or a single company that wants its own adds a more specific row and wins on precedence.
 * CustomerUpdateRenderer falls back to a built-in shell when no row resolves, so this is an upgrade to
 * the look rather than something the send depends on.
 */
return new class () extends Migration {
    public function up(): void
    {
        if ($this->globalTemplate()->exists()) {
            return;
        }

        DB::table('email_templates')->insert([
            'apps_id' => AppEnums::LEGACY_APP_ID->getValue(),
            'companies_id' => AppEnums::GLOBAL_COMPANY_ID->getValue(),
            'users_id' => 1,
            'name' => CustomerUpdateRenderer::TEMPLATE_NAME,
            'title' => 'Customer product update',
            'template' => File::get(resource_path('views/emails/customerUpdate.blade.php')),
            'is_system' => 1,
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Only the global row. An app or company that added its own is theirs to keep.
        $this->globalTemplate()->delete();
    }

    private function globalTemplate(): Illuminate\Database\Query\Builder
    {
        return DB::table('email_templates')
            ->where('name', CustomerUpdateRenderer::TEMPLATE_NAME)
            ->where('apps_id', AppEnums::LEGACY_APP_ID->getValue())
            ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue());
    }
};
