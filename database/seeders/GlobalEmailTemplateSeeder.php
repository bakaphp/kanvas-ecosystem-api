<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Kanvas\Enums\AppEnums;

/**
 * Seeds global (apps_id=0, companies_id=0) email templates from Blade files. TemplatesRepository falls
 * back to these for every app, and an app or company row with the same name wins on precedence, so
 * existing per-app templates are never overridden.
 *
 * Must run AFTER TemplateSeeder in DatabaseSeeder: that seeder inserts rows with explicit ids (1, 2, ...),
 * so anything inserted before it takes those ids and breaks it with a duplicate-primary-key error.
 */
abstract class GlobalEmailTemplateSeeder extends Seeder
{
    /**
     * @return array<string, string> template name => path relative to resources/
     */
    abstract protected function templates(): array;

    /**
     * A global template whose `[body]` placeholder wraps these ones, or null to send them bare.
     */
    protected function parentTemplateName(): ?string
    {
        return null;
    }

    public function run(): void
    {
        $parentName = $this->parentTemplateName();
        $parentId = $parentName === null ? null : $this->globalTemplate($parentName)->value('id');

        foreach ($this->templates() as $name => $relativePath) {
            $path = resource_path($relativePath);
            if (! File::exists($path) || $this->globalTemplate($name)->exists()) {
                continue;
            }

            DB::table('email_templates')->insert([
                'apps_id' => AppEnums::LEGACY_APP_ID->getValue(),
                'users_id' => 1,
                'companies_id' => AppEnums::GLOBAL_COMPANY_ID->getValue(),
                'parent_template_id' => $parentId,
                'name' => $name,
                'template' => File::get($path),
                'created_at' => now(),
                'is_deleted' => 0,
            ]);
        }
    }

    private function globalTemplate(string $name): Builder
    {
        return DB::table('email_templates')
            ->where('name', $name)
            ->where('apps_id', AppEnums::LEGACY_APP_ID->getValue())
            ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
            ->where('is_deleted', 0);
    }
}
