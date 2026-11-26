<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Templates;

use Database\Seeders\LeadHandOffTemplateSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Enums\AppEnums;
use Kanvas\Intelligence\Enums\HandOffTemplateEnum;
use Kanvas\Templates\Enums\TemplateEngineEnum;
use Tests\TestCase;

final class LeadHandOffTemplateSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function testEveryNameAHandOffAsksForGetsABareTwigTemplateOnce(): void
    {
        $this->seed(LeadHandOffTemplateSeeder::class);
        $this->seed(LeadHandOffTemplateSeeder::class);

        $templates = $this->globalHandOffTemplates();

        $this->assertEqualsCanonicalizing(
            array_column(HandOffTemplateEnum::cases(), 'value'),
            $templates->pluck('name')->all()
        );

        foreach ($templates as $template) {
            $this->assertSame(TemplateEngineEnum::TWIG->value, $template->engine, $template->name);
            $this->assertNull($template->parent_template_id, $template->name);
        }
    }

    /**
     * Read on the seeder's own connection: the Templates model sits on `ecosystem`, which cannot see
     * rows this test's transaction has not committed.
     */
    private function globalHandOffTemplates(): Collection
    {
        return DB::table('email_templates')
            ->whereIn('name', array_column(HandOffTemplateEnum::cases(), 'value'))
            ->where('apps_id', AppEnums::LEGACY_APP_ID->getValue())
            ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
            ->where('is_deleted', 0)
            ->get();
    }
}
